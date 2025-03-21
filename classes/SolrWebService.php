<?php

/**
 * @file classes/SolrWebService.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SolrWebService
 * @brief Implements the communication protocol with the solr search server.
 *
 * This class relies on the PHP curl extension. Please activate the
 * extension before trying to access a solr server through this class.
 */

namespace APP\plugins\generic\lucene\classes;

use APP\core\Application;
use PKP\db\DAORegistry;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\issue\IssueAction;
use APP\journal\Journal;
use APP\search\ArticleSearch;
use APP\submission\Submission;
use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Support\Str;
use GuzzleHttp\Psr7\Query;
use PKP\cache\CacheManager;
use PKP\core\PKPPageRouter;
use PKP\core\PKPString;
use PKP\facades\Locale;

define('SOLR_STATUS_ONLINE', 0x01);
define('SOLR_STATUS_OFFLINE', 0x02);

// Flags used for index maintenance.
define('SOLR_INDEXINGSTATE_DIRTY', true);
define('SOLR_INDEXINGSTATE_CLEAN', false);

// Autosuggest-type:
// - suggester-based: fast and scalable, may propose terms that produce no
//   results, changes to the index will be reflected only after a dictionary
//   rebuild
// - faceting-based: slower and does not scale well, uses lots of cache
//   memory, only makes suggestions that will produce search results, index
//   changes appear immediately
define('SOLR_AUTOSUGGEST_SUGGESTER', 0x01);
define('SOLR_AUTOSUGGEST_FACETING', 0x02);

// The max. number of articles that can
// be indexed in a single batch.
define('SOLR_INDEXING_MAX_BATCHSIZE', 200);

class SolrWebService {
	var $_authUsername;
	var $_authPassword;

	/** @var string The solr search handler name we place our searches on. */
	var $_solrSearchHandler;

	/** @var string The solr core we get our data from. */
	var $_solrCore;

	/** @var string The base URL of the solr server without core and search handler. */
	var $_solrServer;

	/** @var string The unique ID identifying this OJS installation to the solr server. */
	var $_instId;

	/** @var string A description of the last error or message that occurred when calling the service. */
	var $_serviceMessage = '';

	/** @var FileCache A cache containing the available search fields. */
	var $_fieldCache;

	/** @var array A journal cache. */
	var $_journalCache;

	/** @var array An issue cache. */
	var $_issueCache;

	/**
	 * Constructor
	 *
	 * @param $searchHandler string The search handler URL. We assume the embedded server
	 *  as a default.
	 * @param $username string The HTTP BASIC authentication username.
	 * @param $password string The corresponding password.
	 * @param $instId string The unique ID of this OJS installation to partition
	 *  a shared index.
	 */
	function __construct($searchHandler, $username, $password, $instId) {
		// Configure the web service.
		$this->_authUsername = $username;
		$this->_authPassword = $password;

		// Remove trailing slashes.
		$searchHandler = rtrim((string) $searchHandler, '/');

		// Parse the search handler URL.
		$searchHandlerParts = explode('/', $searchHandler);
		$this->_solrSearchHandler = array_pop($searchHandlerParts);
		$this->_solrCore = array_pop($searchHandlerParts);
		$this->_solrServer = implode('/', $searchHandlerParts) . '/';

		// Set the installation ID.
		$this->_instId = $instId;
	}

	//
	// Getters and Setters
	//
	/**
	 * Get the last service message.
	 * @return string
	 */
	function getServiceMessage() {
		return (string)$this->_serviceMessage;
	}

	/**
	 * Retrieve an issue (possibly from the cache).
	 * @param $issueId int
	 * @param $journalId int
	 * @return Issue
	 */
	function _getIssue($issueId, $journalId) {
		if (isset($this->_issueCache[$issueId])) {
			$issue = $this->_issueCache[$issueId];
		} else {
			$issue = Repo::issue()->get($issueId, $journalId);
			$this->_issueCache[$issueId] = $issue;
		}

		return $issue;
	}

	/**
	 * Mark a single article "changed" so that the indexing
	 * back-end will update it during the next batch update.
	 * @param $articleId Integer
	 */
	function setArticleStatus($articleId, $indexingState = SOLR_INDEXINGSTATE_DIRTY) {
		if(!is_numeric($articleId)) {
			assert(false);
			return;
		}
		$submission = Repo::submission()->get($articleId);
		$submission->setData('indexingState', $indexingState);
		Repo::submission()->edit($submission, ['indexingState']);
	}


	//
	// Public API
	//

	/**
	 * Mark the given journal for re-indexing.
	 * @param $journalId integer The ID of the journal to be (re-)indexed.
	 * @return integer The number of articles that have been marked.
	 */
	function markJournalChanged($journalId) {
		if (!is_numeric($journalId)) {
			assert(false);
			return;
		}

		$submissionsIterator = Repo::submission()->getCollector()->filterByContextIds([$journalId])->filterByStatus([Submission::STATUS_PUBLISHED])->getMany();
		$count = 0;
		foreach ($submissionsIterator as $submission) {
			$this->setArticleStatus($submission->getId());
			$count++;
		}
		return $count;
	}

	/**
	 * (Re-)indexes all changed articles in Solr.
	 *
	 * This is the push-indexing implementation of the Solr
	 * web service.
	 *
	 * To control memory usage and response time we
	 * index articles in batches. Batches should be as
	 * large as possible to reduce index commit overhead.
	 *
	 * @param $batchSize integer The maximum number of articles
	 *  to be indexed in this run.
	 * @param $journalId integer If given, restrains index
	 *  updates to the given journal.
	 *
	 * @return integer The number of articles processed or
	 *  null if an error occurred. After an error the method
	 *  SolrWebService::getServiceMessage() will return details
	 *  of the error.
	 */
	function pushChangedArticles($batchSize = SOLR_INDEXING_MAX_BATCHSIZE, $journalId = null) {
		// Internally we just execute an indexing transaction with
		// a push indexing callback.
		return $this->_indexingTransaction(
			[$this, '_pushIndexingCallback'], $batchSize, $journalId
		);
	}

	/**
	 * This method encapsulates an indexing transaction (pull or push).
	 * It consists in generating the XML, transferring it to the server
	 * and marking the transferred articles as "indexed".
	 *
	 * @param $sendXmlCallback callback This function will be called
	 *  with the generated XML.
	 * @param $batchSize integer The maximum number of articles to
	 *  be returned.
	 * @param $journalId integer If given, only retrieves articles
	 *  for the given journal.
	 */
	function _indexingTransaction($sendXmlCallback, $batchSize = SOLR_INDEXING_MAX_BATCHSIZE, $journalId = null) {
		$submissionArray = [];
		$submissionsIterator = Repo::submission()->getCollector()->filterByContextIds([$journalId])->filterByStatus([Submission::STATUS_PUBLISHED])->getMany();
		$count = 0;
		foreach ($submissionsIterator as $submission) {
			if ($submission->getData('indexingState') == SOLR_INDEXINGSTATE_DIRTY) {
				if ($count < SOLR_INDEXING_MAX_BATCHSIZE ) {
					$submissionArray[] = $submission;
					$count++;
				}
			}
		}

		$totalCount = count($submissionArray);

		$changedArticles = $submissionArray;
		// Retrieve articles and overall count from the result set.
		$batchCount = count($submissionArray);
		// Get the XML article list for this batch of articles.
		$numDeleted = null;
		$articleXml = $this->_getArticleListXml($submissionArray, $totalCount, $numDeleted);

		// Let the specific indexing implementation (pull or push)
		// transfer the generated XML.
		$numProcessed = call_user_func_array($sendXmlCallback, [$articleXml, $batchCount, $numDeleted]);

		// Check error conditions.
		if (!is_numeric($numProcessed)) return null;
		$numProcessed = (integer)$numProcessed;
		if ($numProcessed != $batchCount && $numProcessed != $batchCount - $numDeleted) {
			$this->_serviceMessage = __(
				'plugins.generic.lucene.message.indexingIncomplete',
				['numProcessed' => $numProcessed, 'numDeleted' => $numDeleted, 'batchCount' => $batchCount]
			);
		}

		// Now that we are as sure as we can that the counterparty received
		// our XML, let's mark the changed articles as "updated". This "commits"
		// the indexing transaction.
		$journalIds = [];
		foreach($changedArticles as $indexedArticle) {
			$this->setArticleStatus($indexedArticle->getId(), SOLR_INDEXINGSTATE_CLEAN);
			$journalIds[$indexedArticle->getData('contextId')] = true;
		}
		return $numProcessed;
	}

	/**
	 * Retrieve the XML for a batch of articles to be updated.
	 *
	 * @param $articles DBResultFactory The articles to be included
	 *  in the list.
	 * @param $totalCount integer The overall number of changed articles
	 *  (not only the current batch).
	 * @param $numDeleted integer An output parameter that returns
	 *  the number of documents that will be deleted.
	 *
	 * @return string The XML ready to be consumed by the Solr data
	 *  import service.
	 */
	function _getArticleListXml($articles, $totalCount, &$numDeleted) {
		// Create the DOM document.
		$articleDoc = new DOMDocument();

		// Create the root node.
		$articleList = $articleDoc->createElement('articleList');
		$articleDoc->appendChild($articleList);

		// Run through all articles in the batch and generate an
		// XML list for them.
		$numDeleted = 0;
		foreach($articles as $article) {
			$journal = $this->_getJournal($article->getData('contextId'));

			// Check the publication state and subscription state of the article.
			if ($this->_isArticleAccessAuthorized($article)) {
				// Mark the article for update.
				$this->_addArticleXml($articleDoc, $article, $journal);
			} else {
				// Mark the article for deletion.
				$numDeleted++;
				$this->_addArticleXml($articleDoc, $article, $journal, true);
			}
			unset($journal, $article);
		}

		// Add the "has more" attribute so that the server knows
		// whether more batches may have to be pulled (useful for
		// pull indexing only).
		$hasMore = (count($articles) < $totalCount ? 'yes' : 'no');
		$articleDoc->documentElement->setAttribute('hasMore', $hasMore);

		// Return XML.
		return $articleDoc->saveXML();
	}

	/**
	 * Retrieve a journal (possibly from the cache).
	 * @param $journalId int
	 * @return Journal
	 */
	function _getJournal($journalId) {
		if (isset($this->_journalCache[$journalId])) {
			$journal = $this->_journalCache[$journalId];
		} else {
			$journalDao = DAORegistry::getDAO('JournalDAO'); /** @var JournalDAO $journalDao */
			$journal = $journalDao->getById($journalId);
			$journal =
			$this->_journalCache[$journalId] = $journal;
		}

		return $journal;
	}

	/**
	 * Check whether access to the given article
	 * is authorized to the requesting party (i.e. the
	 * Solr server).
	 *
	 * @param $article Submission
	 * @return boolean True if authorized, otherwise false.
	 */
	function _isArticleAccessAuthorized($article) {
		// Did we get a published article?
		if (! $article instanceof Submission) return false;

		// Get the article's journal.
		$journal = $this->_getJournal($article->getData('contextId'));

		if (! $journal instanceof Journal) return false;

		// Get the article's issue.
		$issue = Repo::issue()->get((int) $article->getCurrentPublication()->getData('issueId'));
		if (! ($issue instanceof Issue)) return false;

  		// Only index published articles.
		if (!$issue->getPublished() || $article->getData('status') != Submission::STATUS_PUBLISHED) return false;

		// Make sure the requesting party is authorized to access the article/issue.
		$issueAction = new IssueAction();
		$subscriptionRequired = $issueAction->subscriptionRequired($issue, $journal);
		if ($subscriptionRequired) {
			$isSubscribedDomain = $issueAction->subscribedDomain(Application::get()->getRequest(), $journal, $issue->getId(), $article->getId());

			if (!$isSubscribedDomain) return false;
		}

		// All checks passed successfully - allow access.
		return true;
	}

	/**
	 * Add the metadata XML of a single article to an
	 * XML article list.
	 *
	 * @param $markToDelete boolean If true the returned XML
	 *  will only contain a deletion marker.
	 */
	function _addArticleXml(\DOMDocument $articleDoc, Submission $article, Journal $journal, bool $markToDelete = false) {
		$publication = $article->getCurrentPublication();

		// Get the root node of the list.
		$articleList = $articleDoc->documentElement;

		// Create a new article node.
		$articleNode = $articleDoc->createElement('article');

		// Add ID information.
		$articleNode->setAttribute('id', $article->getId());
		$articleNode->setAttribute('sectionId', $article->getSectionId());
		$articleNode->setAttribute('journalId', $article->getData('contextId'));
		$articleNode->setAttribute('instId', $this->_instId);

		// Set the load action.
		$loadAction = ($markToDelete ? 'delete' : 'replace');
		$articleNode->setAttribute('loadAction', $loadAction);
		$articleList->appendChild($articleNode);

		// The XML for an article marked to be deleted contains no metadata.
		if ($markToDelete) return;

		// Add authors.
		$authors = $publication->getData('authors');
		if (!empty($authors)) {
			$authorList = $articleDoc->createElement('authorList');
			foreach ($authors as $author) { /* @var $author Author */
				$authorNode = $articleDoc->createElement('author');
				$authorNode->appendChild($articleDoc->createTextNode($author->getFullName(true)));
				$authorList->appendChild($authorNode);
			}
			$articleNode->appendChild($authorList);
		}

		// We need the request to retrieve locales and build URLs.
		$request = Application::get()->getRequest();

		// Get all supported locales.
		$site = $request->getSite();
		$supportedLocales = $site->getSupportedLocales() + array_keys($journal->getSupportedLocaleNames());
		assert(!empty($supportedLocales));

		// Add titles, We get the FullTitle so subtitles are also found
		$titleList = $articleDoc->createElement('titleList');
		// Titles are used for sorting, we therefore need
		// them in all supported locales.
		assert(!empty($supportedLocales));
		foreach($supportedLocales as $locale) {
			$localizedTitle = $publication->getFullTitles()[$locale];
			if (!is_null($localizedTitle)) {
				// Add the localized title.
				$titleNode = $articleDoc->createElement('title');
				$titleNode->appendChild($articleDoc->createTextNode($localizedTitle));
				$titleNode->setAttribute('locale', $locale);

				// If the title does not exist in the given locale
				// then use the localized title for sorting only.
				$title = $publication->getData('title', $locale);
				$titleNode->setAttribute('sortOnly', empty($title) ? 'true' : 'false');

				$titleList->appendChild($titleNode);
			}
		}
		$articleNode->appendChild($titleList);

		// Add abstracts.
		$abstracts = $publication->getData('abstract'); // return all locales
		if (!empty($abstracts)) {
			$abstractList = $articleDoc->createElement('abstractList');
			foreach ($abstracts as $locale => $abstract) {
				$abstractNode = $articleDoc->createElement('abstract');
				$abstractNode->appendChild($articleDoc->createTextNode($abstract));
				$abstractNode->setAttribute('locale', $locale);

				$abstractList->appendChild($abstractNode);
			}
			$articleNode->appendChild($abstractList);
		}

		// Add disciplines.
		/** @var SubmissionDisciplineDAO $submissionDisciplineDao */
		$submissionDisciplineDao = DAORegistry::getDAO('SubmissionDisciplineDAO');
		$disciplines = $submissionDisciplineDao->getDisciplines($publication->getId());

		foreach ($disciplines as $locale => $discipline) {
			if (empty($discipline)) {
				unset($disciplines[$locale]);
			}
		}

		if (!empty($disciplines)) {
			$disciplineList = $articleDoc->createElement('disciplineList');
			$locales = array_keys($disciplines);
			foreach($locales as $locale) {
				$discipline = '';
				if (isset($disciplines[$locale])) {
					foreach($disciplines[$locale] as $localizedDiscipline) {
						$disciplineNode = $articleDoc->createElement('discipline');
						$disciplineNode->appendChild($articleDoc->createTextNode($localizedDiscipline));
						$disciplineNode->setAttribute('locale', $locale);
						$disciplineList->appendChild($disciplineNode);
					}
				}
			}
			$articleNode->appendChild($disciplineList);
		}

		/** @var SubmissionSubjectDAO $submissionSubjectDao */
		$submissionSubjectDao = DAORegistry::getDAO('SubmissionSubjectDAO');
		$subjects = $submissionSubjectDao->getSubjects($publication->getId());
		foreach ($subjects as $locale => $subject) {
			if (empty($subject)) {
				unset($subjects[$locale]);
			}
		}

		// in OJS2, keywords and subjects where put together into the subject Facet.
		// For now, I do the same here. TODO: Decide if this is wanted.
		/** @var mixed SubmissionKeywordDAO $submissionKeywordDAO */
		$submissionKeywordDAO = DAORegistry::getDAO('SubmissionKeywordDAO');
		$keywords = $submissionKeywordDAO->getKeywords($publication->getId());
		foreach($keywords as $locale => $keyword) {
			if (empty($keyword)) {
				unset($keywords[$locale]);
			}
		}

		// Add subjects and keywords.
		if (!empty($subjects) || !empty($keywords)) {
			$subjectList = $articleDoc->createElement('subjectList');

			if (!is_array($subjects)) $subjects = [];
			if (!is_array($keywords)) $keywords = [];
			$locales = array_unique(array_merge(array_keys($subjects), array_keys($keywords)));
			foreach($locales as $locale) {
				$subject = '';
				if (isset($subjects[$locale])) {
					foreach($subjects[$locale] as $localizedSubject) {
						if (!empty($subject)) $subject .= '; ';
						$subject .= $localizedSubject;
					}
				}
				if (isset($keywords[$locale])) {
					foreach($keywords[$locale] as $localizedKeyword) {
						if (!empty($subject)) $subject .= '; ';
						$subject .= $localizedKeyword;
					}
				}

				$subjectNode = $articleDoc->createElement('subject');
				$subjectNode->appendChild($articleDoc->createTextNode($subject));
				$subjectNode->setAttribute('locale', $locale);
				$subjectList->appendChild($subjectNode);

			}
			$articleNode->appendChild($subjectList);
		}

		// Add type.
		$types = $publication->getData('type'); // return all locales
		if (!empty($types)) {
			$typeList = $articleDoc->createElement('typeList');
			foreach ($types as $locale => $type) {
				$typeNode = $articleDoc->createElement('type');
				$typeNode->appendChild($articleDoc->createTextNode($type));
				$typeNode->setAttribute('locale', $locale);
				$typeList->appendChild($typeNode);
			}
			$articleNode->appendChild($typeList);
		}

		// Add coverage.
		$coverage = (array) $publication->getData('coverage');
		if (!empty($coverage)) {
			$coverageList = $articleDoc->createElement('coverageList');
			foreach($coverage as $locale => $coverageLocalized) {
				$coverageNode = $articleDoc->createElement('coverage');
				$coverageNode->appendChild($articleDoc->createTextNode($coverageLocalized));
				$coverageNode->setAttribute('locale', $locale);
				$coverageList->appendChild($coverageNode);
			}
			$articleNode->appendChild($coverageList);
		}

		// Add journal titles.
		$journalTitleList = $articleDoc->createElement('journalTitleList');
		// Journal titles are used for sorting, we therefore need
		// them in all supported locales.
		foreach($supportedLocales as $locale) {
			$localizedTitle = $journal->getName($locale);
			$sortOnly = false;
			if (is_null($localizedTitle)) {
				// If the title does not exist in the given locale
				// then use the localized title for sorting only.
				$journalTitle = $journal->getLocalizedName();
				$sortOnly = true;
			} else {
				$journalTitle = $localizedTitle;
			}

			$journalTitleNode = $articleDoc->createElement('journalTitle');
			$journalTitleNode->appendChild($articleDoc->createTextNode($journalTitle));
			$journalTitleNode->setAttribute('locale', $locale);
			$journalTitleNode->setAttribute('sortOnly', $sortOnly ? 'true' : 'false');

			$journalTitleList->appendChild($journalTitleNode);
		}
		$articleNode->appendChild($journalTitleList);

		// Add publication dates.
		$publicationDate = $publication->getData('datePublished');
		if (!empty($publicationDate)) {
			// Transform and store article publication date.
			$publicationDate = $this->_convertDate($publicationDate);
			$dateNode = $articleDoc->createElement('publicationDate');
			$dateNode->appendChild($articleDoc->createTextNode($publicationDate));
			$articleNode->appendChild($dateNode);
		}

		$issueId = $publication->getData('issueId');
		if (is_numeric($issueId)) {
			$issue = Repo::issue()->get($issueId);
			if ($issue instanceof Issue) {
				$issuePublicationDate = $issue->getDatePublished();
				if (!empty($issuePublicationDate)) {
					// Transform and store issue publication date.
					$issuePublicationDate = $this->_convertDate($issuePublicationDate);
					$dateNode = $articleDoc->createElement('issuePublicationDate');
					$dateNode->appendChild($articleDoc->createTextNode($issuePublicationDate));
					$articleNode->appendChild($dateNode);
				}
			}
		}

		// We need a PageRouter to build file URLs.
		$router = $request->getRouter(); /* @var $router PageRouter */
		if (! $router instanceof PKPPageRouter) {
			$router = new PKPPageRouter();
			$application = Application::get();
			$router->setApplication($application);
		}

		$galleys = $publication->getData('galleys');
		$galleyList = null;
		foreach ($galleys as $galley) {
			if (!$galley->getData('submissionFileId')) continue;
			$locale = $galley->getLocale();
			$galleyUrl = $router->url($request, $journal->getPath(), 'article', 'download', [$article->getBestId(), $galley->getBestGalleyId()]);
			if (!empty($locale) && !empty($galleyUrl)) {
				if (is_null($galleyList)) {
					$galleyList = $articleDoc->createElement('galleyList');
				}
				$galleyNode = $articleDoc->createElement('galley');
				$galleyNode->setAttribute('locale', $locale);
				$galleyNode->setAttribute('fileName', $galleyUrl);
				$galleyList->appendChild($galleyNode);
			}
		}

		// Wrap the galley XML as CDATA.
		if (!is_null($galleyList)) {
			$galleyXml = $articleDoc->saveXml($galleyList);
			$galleyOuterNode = $articleDoc->createElement('galley-xml');
			$cdataNode = $articleDoc->createCDATASection($galleyXml);
			$galleyOuterNode->appendChild($cdataNode);
			$articleNode->appendChild($galleyOuterNode);
		}
	}

	/**
	 * Convert a date from local time (unix timestamp
	 * or ISO date string) to UTC time as understood
	 * by solr.
	 *
	 * NB: Using intermediate unix timestamps can be
	 * a problem in older PHP versions, especially on
	 * Windows where negative timestamps are not supported.
	 *
	 * As Solr requires PHP5 that should not be a big
	 * problem in practice, except for electronic
	 * publications that go back until earlier than 1901.
	 * It does not seem probable that such a situation
	 * could realistically arise with OJS.
	 *
	 * @param $timestamp int|string Unix timestamp or local ISO time.
	 * @return string ISO UTC timestamp
	 */
	function _convertDate($timestamp) {
		if (is_numeric($timestamp)) {
			// Assume that this is a unix timestamp.
			$timestamp = (integer) $timestamp;
		} else {
			// Assume that this is an ISO timestamp.
			$timestamp = strtotime($timestamp);
		}

		// Convert to UTC as understood by solr.
		return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
	}

	/**
	 * Retrieves a batch of articles in XML format.
	 *
	 * This is the pull-indexing implementation of the Solr
	 * web service.
	 *
	 * To control memory usage and response time we
	 * index articles in batches. Batches should be as
	 * large as possible to reduce index commit overhead.
	 *
	 * @param $sendXmlCallback callback This function will be called
	 *  with the generated XML. We do not send the XML from here
	 *  as communication with the requesting counterparty should
	 *  be done from the controller and not from the back-end.
	 * @param $batchSize integer The maximum number of articles
	 *  to be returned.
	 * @param $journalId integer If given, only returns
	 *  articles from the given journal.
	 *
	 * @return integer The number of articles processed or
	 *  null if an error occurred. After an error the method
	 *  SolrWebService::getServiceMessage() will return details
	 *  of the error.
	 */
	function pullChangedArticles($pullIndexingCallback, $batchSize = SOLR_INDEXING_MAX_BATCHSIZE, $journalId = null) {
		// Internally we just execute an indexing transaction with
		// a pull indexing callback.
		return $this->_indexingTransaction(
			$pullIndexingCallback, $batchSize, $journalId
		);
	}

	/**
	 * Deletes the given article from the Solr index.
	 *
	 * @param $articleId integer The ID of the article to be deleted.
	 *
	 * @return boolean true if successful, otherwise false.
	 */
	function deleteArticleFromIndex($articleId) {
		$xml = '<id>' . $this->_instId . '-' . $articleId . '</id>';
		return $this->_deleteFromIndex($xml);
	}

	/**
	 * Delete documents from the index (by
	 * ID or by query).
	 * @param $xml string The documents to delete.
	 * @return boolean true, if successful, otherwise false.
	 */
	function _deleteFromIndex($xml) {
		// Add the deletion tags.
		$xml = '<delete>' . $xml . '</delete>';

		// Post the XML.
		$url = $this->_getUpdateUrl() . '?commit=true';
		$result = $this->_makeRequest($url, $xml, 'POST');
		if (is_null($result)) return false;

		// Check the return status (must be 0).
		$nodeList = $result->query('/response/lst[@name="responseHeader"]/int[@name="status"]');
		if($nodeList->length != 1) return false;
		$resultNode = $nodeList->item(0);
		if ($resultNode->textContent === '0') return true;
	}

	/**
	 * Returns the solr update endpoint.
	 *
	 * @return string
	 */
	function _getUpdateUrl() {
		$updateUrl = $this->_solrServer . $this->_solrCore . '/update';
		return $updateUrl;
	}

	/**
	 * Make a request
	 *
	 * @param $url string The request URL
	 * @param $params mixed array (key value pairs) or string request parameters
	 * @param $method string GET or POST
	 *
	 * @return DOMXPath An XPath object with the response loaded. Null if an error occurred.
	 *  See _serviceMessage for more details about the error.
	 */
	function _makeRequest($url, $params = [], $method = 'GET') : ?DOMXpath {
		$application = Application::get();

		$client = $application->getHttpClient();
		$guzzleParams = [
			'auth' => [$this->_authUsername, $this->_authPassword]
		];
		if ($method == 'POST') {
			$guzzleParams['headers'] = ['Content-Type' => 'text/xml; charset=utf-8'];
			if (is_array($params)) $guzzleParams['form_params'] = $params;
			else $guzzleParams['body'] = $params;
		} elseif ($method == 'GET') {
			$guzzleParams['query'] = Query::build($params);
		} else {
			throw new Exception('Unknown request method!');
		}
		// $guzzleParams['debug'] = true;
		$response = $client->request($method, $url, $guzzleParams);

		// Did we get a response at all?
		if (!$response) {
			$this->_serviceMessage = __('plugins.generic.lucene.message.searchServiceOffline');
			return null;
		}

		// Did we get a "200 - OK" response?
		$status = $response->getStatusCode();
		if ($status != 200) {
			// We show a generic error message to the end user
			// to avoid information leakage and log the exact error.
			error_log($application->getName() . ' - Lucene plugin:' . PHP_EOL . "The Lucene web service returned a status code $status and the message" . PHP_EOL . (string) $response->getBody());
			$this->_serviceMessage = __('plugins.generic.lucene.message.webServiceError');
			return null;
		}

		// Prepare and return an XPath object.
		$responseDom = new DOMDocument();
		$responseDom->loadXML((string) $response->getBody());
		return new DOMXPath($responseDom);
	}

	/**
	 * Deletes all articles of a journal or of the
	 * installation from the Solr index.
	 *
	 * @param $journalId integer If given, only articles
	 *  from this journal will be deleted.
	 * @return boolean true if successful, otherwise false.
	 */
	function deleteArticlesFromIndex($journalId = null) {
		// Delete only articles from one journal if a
		// journal ID is given.
		$journalQuery = '';
		if (is_numeric($journalId)) {
			$journalQuery = ' AND journal_id:' . $this->_instId . '-' . $journalId;
		}

		// Delete all articles of the installation (or journal).
		$xml = '<query>inst_id:' . $this->_instId . $journalQuery . '</query>';
		return $this->_deleteFromIndex($xml);
	}

	/**
	 * Execute a search against the Solr search server.
	 *
	 * @param $searchRequest SolrSearchRequest
	 * @param $totalResults integer An output parameter returning the
	 *  total number of search results found by the query. This differs
	 *  from the actual number of returned results as the search can
	 *  be limited.
	 *
	 * @return array An array of search results. The main keys are result
	 *  types. These are "scoredResults" and "alternativeSpelling".
	 *  The keys in the "scoredResults" sub-array are scores (1-9999) and the
	 *  values are article IDs. The alternative spelling sub-array returns
	 *  an alternative query string (if any) and the number of hits for this
	 *  string. Null if an error occurred while querying the server.
	 */
	function retrieveResults($searchRequest, &$totalResults, $solr7 = false) {
		// Construct the main query.
		$params = $this->_getSearchQueryParameters($searchRequest, $solr7);

		// If we have no filters at all then return an
		// empty result set.
		if (!isset($params['q'])) return [];

		// Pagination.
		$itemsPerPage = $searchRequest->getItemsPerPage();
		$params['start'] = ($searchRequest->getPage() - 1) * $itemsPerPage;
		$params['rows'] = $itemsPerPage;

		// Ordering.
		$params['sort'] = $this->_getOrdering($searchRequest->getOrderBy(), $searchRequest->getOrderDir());

		// Highlighting.
		if ($searchRequest->getHighlighting()) {
			$params['hl'] = 'on';
			$params['hl.fl'] = $this->_expandFieldList(['abstract', 'galleyFullText']);
		}

		// Faceting.
		$facetCategories = $searchRequest->getFacetCategories();
		if (!empty($facetCategories)) {
			$params['facet'] = 'on';
			$params['facet.field'] = '';

			// NB: We only add fields in the current UI locale, i.e.
			// facets are considered part of the navigation and not
			// search results.
			$locale = Locale::getLocale();

			// Add facet fields corresponding to the
			// solicited facet categories.
			$facetFields = $this->_getFieldNames('facet');
			$enabledFields = 0;
			$facetFieldsSerialized = [];
			foreach($facetFields['localized'] as $fieldName) {
				if (in_array($fieldName, $facetCategories)) {
					$facetFieldsSerialized[] =  $fieldName . '_' . $locale . '_facet';
					$enabledFields++;
				}
			}
			foreach($facetFields['static'] as $categoryName => $fieldName) {
				if (in_array($categoryName, $facetCategories)) {
					$facetFieldsSerialized[] = $fieldName;
					$enabledFields++;
				}
			}

			$params['facet.field'] = $facetFieldsSerialized;

			if (in_array('publicationDate', $facetCategories)) {
				$params['facet.range'] = 'publicationDate_dt';
				$params['facet.range.start'] = 'NOW/YEAR-50YEARS';
				$params['facet.range.end'] = 'NOW';
				$params['facet.range.gap'] = '+1YEAR';
				$params['facet.range.other'] = 'all';
				$enabledFields++;
			}

			// Did we find all solicited categories?
			assert($enabledFields == count($facetCategories));
		}

		// Boost factors.
		$boostFactors = $searchRequest->getBoostFactors();
		foreach($boostFactors as $field => $valueBoost) {
			foreach ($valueBoost as $value => $boostFactor) {
				if ($boostFactor == 0) {
					// Add a filter query to remove all results.
					if (!isset($params['fq'])) $params['fq'] = [];
					$params['fq'][] = "-$field:$value";
				} elseif ($boostFactor > 0) {
					// Add a boost function query (only works for numeric fields!).
					if (!isset($params['boost'])) $params['boost'] = [];
					// The map function takes the following arguments: 1) the field or
					// function to evaluate, 2) the min value to map, 3) the max value
					// to map, 4) the target value and 5) an optional default value when
					// the field or function value is not between min and max.
					$params['boost'][] = "map($field,$value,$value,$boostFactor,1)";
				}
			}
		}

		// Boost fields. These fields will be used directly to boost internal
		// ranking values. Values in a boost field should vary between 0.5 (half)
		// and 2.0 (double).
		$boostFields = $searchRequest->getBoostFields();
		foreach($boostFields as $boostField) {
			if (!isset($params['boost'])) $params['boost'] = [];
			// Boost fields contain pre-calculated boost values.
			$params['boost'][] = $boostField;
		}

		// Make the search request.
		$url = $this->_getSearchUrl();
		$response = $this->_makeRequest($url, $params);

		// Did we get a result?
		if (is_null($response)) return null;

		// Get the total number of documents found.
		$nodeList = $response->query('/response/result[@name="response"]/@numFound');
		assert($nodeList->length == 1);
		$resultNode = $nodeList->item(0);
		assert(is_numeric($resultNode->textContent));
		$totalResults = (int) $resultNode->textContent;

		// Run through all returned documents and read the ID fields.
		$results = [];
		$docs = $response->query('/response/result/doc');
		foreach ($docs as $doc) {
			$currentDoc = [];
			foreach ($doc->childNodes as $docField) {
				// Get the document field
				$docFieldAtts = $docField->attributes;
				if ($docFieldAtts != null) {
					$fieldNameAtt = $docFieldAtts->getNamedItem('name');

					switch($docField->tagName) {
						case 'float':
							$currentDoc[$fieldNameAtt->value] = (float)$docField->textContent;
							break;

						case 'str':
							$currentDoc[$fieldNameAtt->value] = $docField->textContent;
							break;
					}
				}
			}
			$results[] = $currentDoc;
		}

		// Re-index the result set. There's no need to re-order as the
		// results come back ordered from the solr server.
		$scoredResults = [];
		foreach($results as $resultIndex => $result) {
			// We only need the article ID.
			assert(isset($result['submission_id']));

			// Use the result order to "score" results. This
			// will do relevance sorting and field sorting.
			$score = $itemsPerPage - $resultIndex;

			// Transform the article ID into an integer.
			$articleId = $result['submission_id'];
			if (strpos($articleId, $this->_instId . '-') !== 0) continue;
			$articleId = substr($articleId, strlen($this->_instId . '-'));
			if (!is_numeric($articleId)) continue;

			// Store the result.
			$scoredResults[$score] = (int)$articleId;
		}

		// Read alternative spelling suggestions (if any).
		$spellingSuggestion = null;
		if ($searchRequest->getSpellcheck()) {
			$alternativeSpellingNodeList = $response->query('/response/lst[@name="spellcheck"]/lst[@name="collations"]');

			if ($alternativeSpellingNodeList->length == 1) {
				$alternativeSpellingNode = $alternativeSpellingNodeList->item(0);
				$spellingSuggestion = $alternativeSpellingNode->textContent;

				// Translate back to the current language.
				$spellingSuggestion = $this->_translateSearchPhrase($spellingSuggestion, true);
			}
		}

		// Read highlighting results (if any).
		$highlightedArticles = null;
		if ($searchRequest->getHighlighting()) {
			$highlightedArticles = [];
			$highlightingNodeList = $response->query('/response/lst[@name="highlighting"]/lst');
			foreach($highlightingNodeList as $highlightingNode) { /* @var $highlightingNode DOMElement */
				if ($highlightingNode->hasChildNodes()) {
					$indexArticleId = $highlightingNode->attributes->getNamedItem('name')->nodeValue;
					$articleIdParts = explode('-', $indexArticleId);
					$articleId = array_pop($articleIdParts);
					$excerpt = $highlightingNode->textContent;
					if (empty($excerpt)) {
						$excerpt = $highlightingNode->firstChild->firstChild->textContent;
					}
					if (is_numeric($articleId) && !empty($excerpt)) {
						$highlightedArticles[$articleId] = $excerpt;
					}
				}
			}
		}

		// Read facets (if any).
		$facets = null;
		if (!empty($facetCategories)) {
			$facets = [];

			// Read field-based facets.
			$facetsNodeList = $response->query('/response/lst[@name="facet_counts"]/lst[@name="facet_fields"]/lst');
			foreach($facetsNodeList as $facetFieldNode) { /* @var $facetFieldNode DOMElement */
				$facetField = $facetFieldNode->attributes->getNamedItem('name')->nodeValue;
				$facetFieldParts = explode('_', $facetField);
				$facetCategory = array_shift($facetFieldParts);
				$facets[$facetCategory] = [];
				foreach($facetFieldNode->childNodes as $facetNode) { /* @var $facetNode DOMElement */
					if ($facetNode->attributes != null) {
						$facet = $facetNode->attributes->getNamedItem('name')->nodeValue;
						$facetCount = (integer)$facetNode->textContent;
						// Only select facets that return results and are more selective than
						// the current search criteria.
						if (!empty($facet) && $facetCount > 0 && $facetCount < $totalResults) {
							$facets[$facetCategory][$facet] = $facetCount;
						}
					}
				}
			}

			// Read range-based facets.
			$facetsNodeList = $response->query('/response/lst[@name="facet_counts"]/lst[@name="facet_ranges"]/lst');
			foreach($facetsNodeList as $facetFieldNode) { /* @var $facetFieldNode DOMElement */
				if ($facetFieldNode->attributes != null) {
					$facetField = $facetFieldNode->attributes->getNamedItem('name')->nodeValue;
					$facetFieldParts = explode('_', $facetField);
					$facetCategory = array_shift($facetFieldParts);
					$facets[$facetCategory] = [];
					foreach($facetFieldNode->childNodes as $rangeInfoNode) { /* @var $rangeInfoNode DOMElement */
						// Search for the "counts" node in the range info.
						if ($rangeInfoNode->attributes != null) {
							if($rangeInfoNode->attributes->getNamedItem('name')->nodeValue == 'counts') {
								// Run through all ranges.
								foreach($rangeInfoNode->childNodes as $facetNode) { /* @var $facetNode DOMElement */
									// Retrieve and format the date range facet.
									if ($facetNode->attributes != null) {
										$facet = $facetNode->attributes->getNamedItem('name')->nodeValue;
										$facet = date('Y', strtotime(substr($facet, 0, 10)));
										$facetCount = (integer)$facetNode->textContent;
										// Only select ranges that return results and are more selective than
										// the current search criteria.
										if ($facetCount > 0 && $facetCount < $totalResults) {
											$facets[$facetCategory][$facet] = $facetCount;
										}
									}
								}

								// We do not need the other children.
								break;
							}
						}
					}
				}
			}
		}

		return [
			'scoredResults' => $scoredResults,
			'spellingSuggestion' => $spellingSuggestion,
			'highlightedArticles' => $highlightedArticles,
			'facets' => $facets,
		];
	}


	//
	// Field cache implementation
	//

	/**
	 * Create the edismax query parameters from
	 * a search request.
	 * @param $searchRequest SolrSearchRequest
	 * @return array|null A parameter array or null if something
	 *  went wrong.
	 */
	function _getSearchQueryParameters($searchRequest, $solr7 = false) {
		// Pre-filter and translate query phrases.
		$subQueries = [];
		foreach($searchRequest->getQuery() as $fieldList => $searchPhrase) {
			// Ignore empty search phrases.
			if (empty($fieldList) || empty($searchPhrase)) continue;

			// Translate query keywords.
			$subQueries[$fieldList] = $this->_translateSearchPhrase($searchPhrase);
		}

		// We differentiate between simple and multi-phrase queries.
		$subQueryCount = count($subQueries);
		if ($subQueryCount == 1 || $solr7) {
			// Use a simplified query that allows us to provide
			// alternative spelling suggestions.
			$fieldList = key($subQueries);
			$searchPhrase = current($subQueries);
			$params = $this->_setQuery($fieldList, $searchPhrase, $searchRequest->getSpellcheck());
		} elseif ($subQueryCount > 1) {
			// Initialize the search request parameters.
			$params = [];
			foreach ($subQueries as $fieldList => $searchPhrase) {
				// Construct the sub-query and add it to the search query and params.
				$params = $this->_addSubquery($fieldList, $searchPhrase, $params);
			}
		}

		// Add the installation ID as a filter query.
		$filterFieldsSerialized = [];
		$filterFieldsSerialized[] = 'inst_id:"' . $this->_instId . '"';

		// Add a range search on the publication date (if set).
		$fromDate = $searchRequest->getFromDate();
		$toDate = $searchRequest->getToDate();

		if (!(empty($fromDate) && empty($toDate))) {
			if (empty($fromDate)) {
				$fromDate = '*';
			} else {
				$fromDate = $this->_convertDate($fromDate);
				//exclude the choosen day, the label says: Published after. So add one day
				//$fromDate = $fromDate . '+1DAY';

			}
			if (empty($toDate)) {
				$toDate = '*';
			} else {
				$toDate = $this->_convertDate($toDate);
				//exclude the choosen day, the label says: Published after. So add one day
				//$toDate = $toDate . '-1DAY';
			}
			// We do not cache this filter as reuse seems improbable.
			$filterFieldsSerialized[] = "{!cache=false}publicationDate_dt:[$fromDate TO $toDate]";
		}
		// Add the authors as an filter query (if set).
		$authors = $searchRequest->getAuthors();
		if (!empty($authors)) {
			if (is_array($authors)) {
				foreach ($authors as $author) {
					$filterFieldsSerialized[] = 'authors_txt:' . $author;
				}
			} else {
				$filterFieldsSerialized[] = 'authors_txt:' . $authors;
			}
		}
		// Add the journal as a filter query (if set).
		$journal = $searchRequest->getJournal();
		if ($journal instanceof Journal) {
			$filterFieldsSerialized[] = 'journal_id:"' . $this->_instId . '-' . $journal->getId() . '"';
		}

		// Add excluded IDs as a filter query (if set).
		$excludedIds = $searchRequest->getExcludedIds();
		if (!empty($excludedIds)) {
			$filterFieldsSerialized[] = 'article_id:(-"' . $this->_instId . '-' . implode('" -"' . $this->_instId . '-', $excludedIds) . '")';
		}
		$params['fq'] = $filterFieldsSerialized;
		return $params;
	}

	/**
	 * Translate query keywords.
	 * @param $searchPhrase string
	 * @return The translated search phrase.
	 */
	function _translateSearchPhrase($searchPhrase, $backwards = false) {
		static $queryKeywords;

		if (is_null($queryKeywords)) {
			// Query keywords.
			$queryKeywords = [
				Str::upper(__('search.operator.not')) => 'NOT',
				Str::upper(__('search.operator.and')) => 'AND',
				Str::upper(__('search.operator.or')) => 'OR'
			];
		}

		if ($backwards) {
			$translationTable = array_flip($queryKeywords);
		} else {
			$translationTable = $queryKeywords;
		}

		// Translate the search phrase.
		foreach($translationTable as $translateFrom => $translateTo) {
			$searchPhrase = PKPString::regexp_replace("/(^|\s)$translateFrom(\s|$)/i", "\\1$translateTo\\2", $searchPhrase);
		}

		return $searchPhrase;
	}


	//
	// Private helper methods
	//

	/**
	 * Set the query parameters for a search query.
	 *
	 * @param $fieldList string A list of fields to be queried, separated by '|'.
	 * @param $searchPhrase string The search phrase to be added.
	 * @param $params array The existing query parameters.
	 * @param $spellcheck boolean Whether to switch spellchecking on.
	 */
	function _setQuery($fieldList, $searchPhrase, $spellcheck = false) {
		// Expand the field list to all locales and formats.
		$fieldList = $this->_expandFieldList(explode('|', $fieldList));

		// Add the subquery to the query parameters.
		$params = [
			'defType' => 'edismax',
			'qf' => $fieldList,
			'df' => $fieldList,
			// NB: mm=1 is equivalent to implicit OR
			// This deviates from previous OJS practice, please see
			// http://pkp.sfu.ca/wiki/index.php/OJSdeSearchConcept#Query_Parser
			// for the rationale of this change.
			'mm' => '1'
		];

		// Only set a query if we have one.
		if (!empty($searchPhrase)) {
			$params['q'] = $searchPhrase;
		}

		// Ask for alternative spelling suggestions.
		if ($spellcheck) {
			$params['spellcheck'] = 'on';
		}

		return $params;
	}

	/**
	 * Expand the given list of fields.
	 * @param $fields array
	 * @return string A space-separated field list (e.g. to
	 *  be used in edismax's qf parameter).
	 */
	function _expandFieldList($fields) {
		$expandedFields = [];
		foreach($fields as $field) {
			$expandedFields = array_merge($expandedFields, $this->_getLocalesAndFormats($field));
		}
		return implode(' ', $expandedFields);
	}

	/**
	 * Identify all format/locale versions of the given field.
	 * @param $field string A field name without any extension.
	 * @return array A list of index fields.
	 */
	function _getLocalesAndFormats($field) {
		$availableFields = $this->getAvailableFields('search');
		$fieldNames = $this->_getFieldNames('search');

		$indexFields = [];
		if (isset($availableFields[$field])) {
			if (in_array($field, $fieldNames['multiformat'])) {
				// This is a multiformat field.
				foreach($availableFields[$field] as $format => $locales) {
					foreach($locales as $locale) {
						$indexFields[] = $field . '_' . $format . '_' . $locale;
					}
				}
			} elseif(in_array($field, $fieldNames['localized'])) {
				// This is a localized field.
				foreach($availableFields[$field] as $locale) {
					$indexFields[] = $field . '_' . $locale;
				}
			} else {
				// This must be a static field.
				assert(isset($fieldNames['static'][$field]));
				$indexFields[] = $fieldNames['static'][$field];
			}
		}
		return $indexFields;
	}

	/**
	 * Returns an array with all (dynamic) fields in the index.
	 *
	 * NB: This is cached data so after an index update we may
	 * have to flush the index to re-read the current index state.
	 *
	 * @param $fieldType string Either 'search' or 'sort'.
	 * @return array
	 */
	function getAvailableFields($fieldType) {
		$cache = $this->_getCache();
		$fieldCache = $cache->get($fieldType);
		return $fieldCache;
	}

	/**
	 * Get the field cache.
	 * @return FileCache
	 */
	function _getCache() {
		if (!isset($this->_fieldCache)) {
			// Instantiate a file cache.
			$cacheManager = CacheManager::getManager();
			$this->_fieldCache = $cacheManager->getFileCache(
				'plugins-lucene', 'fieldCache',
				[$this, '_cacheMiss']
			);

			// Check to see if the data is outdated (24 hours).
			$cacheTime = $this->_fieldCache->getCacheTime();
			if (!is_null($cacheTime) && $cacheTime < (time() - 24 * 60 * 60)) {
				$this->_fieldCache->flush();
			}
		}
		return $this->_fieldCache;
	}

	/**
	 * Return a list of all text fields that may occur in the
	 * index.
	 * @param $fieldType string "search", "sort" or "all"
	 *
	 * @return array
	 */
	function _getFieldNames($fieldType) {
		$fieldNames = [
			'search' => [
				'localized' => [
					'title', 'abstract', 'discipline', 'subject',
					'type', 'coverage',
				],
				'multiformat' => [
					'galleyFullText'
				],
				'static' => [
					'authors' => 'authors_txt',
					'publicationDate' => 'publicationDate_dt'
				]
			],
			'sort' => [
				'localized' => [
					'title', 'journalTitle'
				],
				'multiformat' => [],
				'static' => [
					'authors' => 'authors_txtsort',
					'publicationDate' => 'publicationDate_dtsort',
					'issuePublicationDate' => 'issuePublicationDate_dtsort'
				]
			],
			'facet' => [
				'localized' => [
					'discipline', 'subject', 'type', 'coverage', 'journalTitle'
				],
				'multiformat' => [],
				'static' => [
					'authors' => 'authors_facet',
				]
			]
		];
		if ($fieldType == 'all') {
			return $fieldNames;
		} else {
			assert(isset($fieldNames[$fieldType]));
			return $fieldNames[$fieldType];
		}
	}

	/**
	 * Add a subquery to the search query.
	 *
	 * NB: subqueries do not support collation (for alternative
	 * spelling suggestions).
	 *
	 * @param $fieldList string A list of fields to be queried, separated by '|'.
	 * @param $searchPhrase string The search phrase to be added.
	 * @param $params array The existing query parameters.
	 */
	function _addSubquery($fieldList, $searchPhrase, $params) {
		// Get the list of fields to be queried.
		$fields = explode('|', $fieldList);

		// Expand the field list to all locales and formats.
		$fieldList = $this->_expandFieldList($fields);

		// Determine a query parameter name for this field list.
		if (count($fields) == 1) {
			// If we have a single field in the field list then
			// use the field name as alias.
			$fieldAlias = array_pop($fields);
		} else {
			// Use a generic name for multi-field searches.
			$fieldAlias = 'multi';
		}
		$fieldAlias = "q.$fieldAlias";

		// Make sure that the alias is unique.
		$fieldSuffix = '';
		while (isset($params[$fieldAlias . $fieldSuffix])) {
			if (empty($fieldSuffix)) $fieldSuffix = 1;
			$fieldSuffix ++;
		}
		$fieldAlias = $fieldAlias . $fieldSuffix;

		// Construct a subquery.
		// NB: mm=1 is equivalent to implicit OR
		// This deviates from previous OJS practice, please see
		// http://pkp.sfu.ca/wiki/index.php/OJSdeSearchConcept#Query_Parser
		// for the rationale of this change.
		$subQuery = "+_query_:\"{!edismax mm=1 qf='$fieldList' v=\$$fieldAlias}\"";

		// Add the subquery to the query parameters.
		if (isset($params['q'])) {
			$params['q'] .= ' ' . $subQuery;
		} else {
			$params['q'] = $subQuery;
		}

		//since v8 of SOLR, The eDisMax parser by default no longer allows subqueries that specify a Solr
		//parser using either local parameters, or the older _query_ magic field trick.
		//see: https://lucene.apache.org/solr/guide/8_0/the-extended-dismax-query-parser.html#extended-dismax-parameters
		$params['uf'] = '* _query_';

		$params['qf'] = $fieldList;
		$params['df'] = $fieldList;

		$params[$fieldAlias] = $searchPhrase;
		return $params;
	}

	/**
	 * Generate the ordering parameter of a search query.
	 * @param $field string the field to order by
	 * @param $direction boolean true for ascending, false for descending
	 * @return string The ordering to be used (default: descending relevance).
	 */
	function _getOrdering($field, $direction) {
		// Translate the direction.
		$dirString = ($direction?' asc':' desc');

		// Special case: relevance ordering.
		if ($field == 'score') {
			return $field . $dirString;
		}

		// We order by descending relevance by default.
		$defaultSort = 'score desc';

		// We have to check whether the sort field is
		// available in the index.
		$availableFields = $this->getAvailableFields('sort');
		if (!isset($availableFields[$field])) return $defaultSort;

		// Retrieve all possible sort fields.
		$fieldNames = $this->_getFieldNames('sort');

		// Order by a static (non-localized) field.
		if(isset($fieldNames['static'][$field])) {
			return $fieldNames['static'][$field] . $dirString . ',' . $defaultSort;
		}

		// Order by a localized field.
		if (in_array($field, $fieldNames['localized'])) {
			// We can only sort if the current locale is indexed.
			$currentLocale = Locale::getLocale();
			if (in_array($currentLocale, $availableFields[$field])) {
				// Return the localized sort field name.
				return $field . '_' . $currentLocale . '_txtsort' . $dirString . ',' . $defaultSort;
			}
		}

		// In all other cases return the default ordering.
		return $defaultSort;
	}

	/**
	 * Returns the solr search endpoint.
	 * @return string
	 */
	function _getSearchUrl() {
		$searchUrl = $this->_solrServer . $this->_solrCore . '/' . $this->_solrSearchHandler;
		return $searchUrl;
	}

	/**
	 * Retrieve auto-suggestions from the solr index
	 * corresponding to the given user input.
	 *
	 * @param $searchRequest SolrSearchRequest Active search filters. Choosing
	 *  the faceting auto-suggest implementation via $autosuggestType will
	 *  pre-filter auto-suggestions based on this search request. In case of
	 *  the suggester component, the search request will simply be ignored.
	 * @param $fieldName string The field to suggest values for. Values are
	 *  queried on field level to improve relevance of suggestions.
	 * @param $userInput string Partial query input. This input will be split
	 *  split up. Only the last query term will be used to suggest values.
	 * @param $autosuggestType string One of the SOLR_AUTOSUGGEST_* constants.
	 *  The faceting implementation is slower but will return more relevant
	 *  suggestions. The suggestor implementation is faster and scales better
	 *  in large deployments. It will return terms from a field-specific global
	 *  dictionary, though, e.g. from different journals.
	 *
	 * @return array A list of suggested queries
	 */
	function getAutosuggestions($searchRequest, $fieldName, $userInput, $autosuggestType) {
		// Validate input.
		$articleSearch = new ArticleSearch();
		$allowedFieldNames = array_values($articleSearch->getIndexFieldMap());
		$allowedFieldNames[] = 'query';
		$allowedFieldNames[] = 'indexTerms';
		if (!in_array($fieldName, $allowedFieldNames)) return [];

		// Check the auto-suggest type.
		$autosuggestTypes = array(SOLR_AUTOSUGGEST_SUGGESTER, SOLR_AUTOSUGGEST_FACETING);
		if (!in_array($autosuggestType, $autosuggestTypes)) return [];

		// Execute an auto-suggest request.
		$url = $this->_getAutosuggestUrl($autosuggestType);
		if ($autosuggestType == SOLR_AUTOSUGGEST_SUGGESTER) {
			$suggestions = $this->_getSuggesterAutosuggestions($url, $userInput, $fieldName);
		} else {
			$suggestions = $this->_getFacetingAutosuggestions($url, $searchRequest, $userInput, $fieldName);
		}
		return $suggestions;
	}

	/**
	 * Returns the solr auto-suggestion endpoint.
	 * @param $autosuggestType string One of the SOLR_AUTOSUGGEST_* constants
	 * @return string
	 */
	function _getAutosuggestUrl($autosuggestType) {
		$autosuggestUrl = $this->_solrServer . $this->_solrCore;
		switch ($autosuggestType) {
			case SOLR_AUTOSUGGEST_SUGGESTER:
				$autosuggestUrl .= '/dictBasedSuggest';
				break;

			case SOLR_AUTOSUGGEST_FACETING:
				$autosuggestUrl .= '/facetBasedSuggest';
				break;

			default:
				$autosuggestUrl = null;
				assert(false);
		}
		return $autosuggestUrl;
	}

	/**
	 * Retrieve auto-suggestions from the suggester service.
	 * @param $url string
	 * @param $userInput string
	 * @param $fieldName string
	 * @return array The generated suggestions.
	 */
	function _getSuggesterAutosuggestions($url, $userInput, $fieldName) {
		// Select the dictionary appropriate for the field
		// the user input is coming from.
		if ($fieldName == 'query') {
			$dictionary = 'all';
		} else {
			$dictionary = $fieldName;
		}

		// Generate parameters for the suggester component.
		$params = [
			'q' => $userInput,
			'spellcheck.dictionary' => $dictionary
		];

		// Make the request.
		$response = $this->_makeRequest($url, $params);
		if (!($response instanceof DOMXPath)) return [];

		// Extract suggestions for the last word in the query.
		$nodeList = $response->query('//lst[@name="suggestions"]/lst[last()]');
		if ($nodeList->length == 0) return [];
		$suggestionNode = $nodeList->item(0);
		foreach($suggestionNode->childNodes as $childNode) {
			if ($childNode->attributes != null) {
				$nodeType = $childNode->attributes->getNamedItem('name')->value;
				switch($nodeType) {
					case 'startOffset':
					case 'endOffset':
						$$nodeType = ((int)$childNode->textContent);
						break;

					case 'suggestion':
						$suggestions = [];
						foreach($childNode->childNodes as $suggestionNodeChild) {
							if ($suggestionNodeChild->localName == 'str') {
								$suggestions[] = $suggestionNodeChild->textContent;
							}
						}
						break;
				}
			}
		}

		// Check whether the suggestion really concerns the
		// last word of the user input.
		if (!(isset($startOffset) && isset($endOffset)
			&& Str::length($userInput) == $endOffset)) return [];

		// Replace the last word in the user input
		// with the suggestions maintaining case.
		foreach($suggestions as &$suggestion) {
			$suggestion = $userInput . Str::substr($suggestion, $endOffset - $startOffset);
		}
		return $suggestions;
	}

	/**
	 * Retrieve auto-suggestions from the faceting service.
	 * @param $url string
	 * @param $searchRequest SolrSearchRequest
	 * @param $userInput string
	 * @param $fieldName string
	 * @return array The generated suggestions.
	 */
	function _getFacetingAutosuggestions($url, $searchRequest, $userInput, $fieldName) {
		// Remove special characters from the user input.
		$searchTerms = strtr($userInput, '"()+-|&!', '		');

		// Cut off the last search term.
		$searchTerms = explode(' ', $searchTerms);
		$facetPrefix = array_pop($searchTerms);
		if (empty($facetPrefix)) return [];

		// Use the remaining search query to pre-filter
		// facet results. This may be an invalid query
		// but edismax will deal gracefully with syntax
		// errors.
		$userInput = substr($userInput, 0, -Str::length($facetPrefix));

		switch ($fieldName) {
			case 'query':
				// The 'query' filter goes against all fields.
				$articleSearch = new ArticleSearch();
				$solrFields = array_values($articleSearch->getIndexFieldMap());
				break;

			case 'indexTerms':
				// The 'index terms' filter goes against keyword index fields.
				$solrFields = ['discipline', 'subject', 'type', 'coverage'];
				break;

			default:
				// All other filters can be used directly.
				$solrFields = [$fieldName];
		}
		$solrFieldString = implode('|', $solrFields);
		$searchRequest->addQueryFieldPhrase($solrFieldString, $userInput);

		// Construct the main query.
		$params = $this->_getSearchQueryParameters($searchRequest);
		if (!isset($params['q'])) {
			// Use a catch-all query in case we have no limiting
			// search.
			$params['q'] = '*:*';
		}
		if ($fieldName == 'query') {
			$params['qf'] = '*';
			$params['facet.field'] = 'default_spell';
		} else {
			$params['q'] .= '*';
			$params['facet.field'] = $fieldName . '_spell';
		}
		$facetPrefixLc = strtolower($facetPrefix);

		$params['facet.prefix'] = $facetPrefixLc;

		// Make the request.
		$response = $this->_makeRequest($url, $params);
		if ( ! $response instanceof DOMXPath ) return [];

		// Extract term suggestions.
		$nodeList = $response->query('//lst[@name="facet_fields"]/lst/int/@name');
		if ($nodeList->length == 0) return [];
		$termSuggestions = [];
		foreach($nodeList as $childNode) {
			$termSuggestions[] = $childNode->value;
		}

		// Add the term suggestion to the remaining user input.
		$suggestions = [];
		foreach($termSuggestions as $termSuggestion) {
			// Restore case if possible.
			if (strpos($termSuggestion, $facetPrefixLc) === 0) {
				$termSuggestion = $facetPrefix . substr($termSuggestion, strlen($facetPrefix));
			}
			$suggestions[] = $userInput . $termSuggestion;
		}
		return $suggestions;
	}

	/**
	 * Retrieve "interesting terms" from a document to be used in a "similar
	 * documents" search.
	 *
	 * @param $articleId integer The article from which we retrieve "interesting
	 *  terms".
	 *
	 * @return array An array of terms that can be used to execute a search
	 *  for similar documents.
	 */
	function getInterestingTerms($articleId) {
		// Make a request to the MLT request handler.
		$url = $this->_getInterestingTermsUrl();
		$params = [
			'q' => $this->_instId . '-' . $articleId,
			'mlt.fl' => $this->_expandFieldList(['title', 'abstract', 'galleyFullText']),
			'mlt.qf' => $this->_expandFieldList(['title', 'abstract', 'galleyFullText']),
			'df' => 'submission_id',
		];
		$response = $this->_makeRequest($url, $params); /** @var DOMXPath $response  */
		if (! $response instanceof DOMXPath) return null;

		// Check whether a query will actually return something.
		// This is an optimization to avoid unnecessary requests
		// in case they won't return anything interesting.
		$nodeList = $response->query('/response/result[@name="response"]/@numFound');
		if ($nodeList->length != 1) return [];
		$numFound = $nodeList->item(0)->textContent;
		if ($numFound = 0) return [];

		// Retrieve interesting terms from the response.
		$terms = [];
		$nodeList = $response->query('/response/arr[@name="interestingTerms"]/str');
		foreach ($nodeList as $node) {
			// Get the field name.
			$term = $node->textContent;
			// Filter reverse wildcard terms.
			if (substr($term,0,3) === '#1;') continue;
			$terms[] = $term;
		}
		return $terms;
	}

	/**
	 * Returns the solr endpoint to retrieve
	 * "interesting terms" from a given document.
	 * @return string
	 */
	function _getInterestingTermsUrl() {
		return $this->_solrServer . $this->_solrCore . '/simdocs';
	}

	/**
	 * Flush the field cache.
	 */
	function flushFieldCache() {
		$cache = $this->_getCache();
		$cache->flush();
	}

	/**
	 * Retrieve a document directly from the index
	 * (for testing/debugging purposes only).
	 *
	 * @param $articleId
	 *
	 * @return array The document fields.
	 */
	function getArticleFromIndex($articleId) {
		// Make a request to the luke request handler.
		$url = $this->_getCoreAdminUrl() . 'luke';
		$params = ['id' => $this->_instId . '-' . $articleId];
		$response = $this->_makeRequest($url, $params);
		if (! $response instanceof DOMXPath) return false;

		// Retrieve all fields from the response.
		$doc = [];
		$nodeList = $response->query('/response/lst[@name="doc"]/doc[@name="solr"]/str');
		foreach ($nodeList as $node) {
			// Get the field name.
			$fieldName = $node->attributes->getNamedItem('name')->value;
			$fieldValue = $node->textContent;
			$doc[$fieldName] = $fieldValue;
		}

		return $doc;
	}

	/**
	 * Identifies the solr core-specific admin endpoint
	 * from the search handler URL.
	 *
	 * @return string
	 */
	function _getCoreAdminUrl() {
		$adminUrl = $this->_solrServer . $this->_solrCore . '/admin/';
		return $adminUrl;
	}

	/**
	 * Checks the solr server status.
	 *
	 * @return integer One of the SOLR_STATUS_* constants.
	 */
	function getServerStatus() {
		// Make status request.
		$url = $this->_getAdminUrl() . 'cores';
		$params = [
			'action' => 'STATUS',
			'core' => $this->_solrCore
		];
		$response = $this->_makeRequest($url, $params);

		// Did we get a response at all?
		if (is_null($response)) {
			return SOLR_STATUS_OFFLINE;
		}

		// Is the core online?
		assert($response instanceof DOMXPath);
		$nodeList = $response->query('/response/lst[@name="status"]/lst[@name="ojs"]/lst[@name="index"]/int[@name="numDocs"]');

		// Check whether the core is active.
		if ($nodeList->length != 1) {
			$this->_serviceMessage = __('plugins.generic.lucene.message.coreNotFound', ['core' => $this->_solrCore]);
			return SOLR_STATUS_OFFLINE;
		}

		$this->_serviceMessage = __('plugins.generic.lucene.message.indexOnline', ['numDocs' => $nodeList->item(0)->textContent]);
		return SOLR_STATUS_ONLINE;
	}

	/**
	 * Identifies the general solr admin endpoint from the
	 * search handler URL.
	 *
	 * @return string
	 */
	function _getAdminUrl() {
		$adminUrl = $this->_solrServer . 'admin/';
		return $adminUrl;
	}

	/**
	 * Rebuilds the spelling/auto-suggest dictionaries.
	 */
	function rebuildDictionaries() {
		// Rebuild the auto-suggest dictionary.
		$url = $this->_getAutosuggestUrl(SOLR_AUTOSUGGEST_SUGGESTER);
		$params = [
			'spellcheck.build' => 'true'
		];
		$this->_makeRequest($url, $params);

		// Rebuild the spelling dictionary.
		$url = $this->_getSearchUrl();
		$params = [
			'qf' => 'dummy',
			'spellcheck' => 'on',
			'spellcheck.build' => 'true',
			'spellcheck.dictionary' => 'default'
		];
		$this->_makeRequest($url, $params);
	}

	/**
	 * Reloads external files.
	 */
	function reloadExternalFiles() {
		// Rebuild the auto-suggest dictionary.
		$url = $this->_getReloadExternalFilesUrl();
		$this->_makeRequest($url);
	}

	/**
	 * Returns the solr endpoint to reload external files.
	 */
	function _getReloadExternalFilesUrl() {
		return $this->_solrServer . $this->_solrCore . '/reloadExternalFiles';
	}

	/**
	 * Refresh the cache from the solr server.
	 *
	 * @param $cache FileCache
	 * @param $id string The field type.
	 *
	 * @return array The available field names.
	 */
	function _cacheMiss($cache, $id) {
		assert(in_array($id, ['search', 'sort']));

		// Get the fields that may be found in the index.
		$fields = $this->_getFieldNames('all');

		// Prepare the cache.
		$fieldCache = [];
		foreach(['search', 'sort'] as $fieldType) {
			$fieldCache[$fieldType] = [];
			foreach(['localized', 'multiformat', 'static'] as $fieldSubType) {
				if ($fieldSubType == 'static') {
					foreach($fields[$fieldType][$fieldSubType] as $fieldName => $dummy) {
						$fieldCache[$fieldType][$fieldName] = [];
					}
				} else {
					foreach($fields[$fieldType][$fieldSubType] as $fieldName) {
						$fieldCache[$fieldType][$fieldName] = [];
					}
				}
			}
		}

		// Make a request to the luke request handler.
		$url = $this->_getCoreAdminUrl() . 'luke';
		$response = $this->_makeRequest($url);
		if (! $response instanceof DOMXPath) return false;

		// Retrieve the field names from the response.
		$nodeList = $response->query('/response/lst[@name="fields"]/lst/@name');
		foreach ($nodeList as $node) {
			// Get the field name.
			$fieldName = $node->textContent;

			// Split the field name.
			$fieldNameParts = explode('_', $fieldName);

			// Identify the field type.
			$fieldSuffix = array_pop($fieldNameParts);
			if (in_array($fieldSuffix, ['spell', 'facet'])) continue;
			if (strpos($fieldSuffix, 'sort') !== false) {
				$fieldType = 'sort';
				$fieldSuffix = array_pop($fieldNameParts);
			} else {
				$fieldType = 'search';
			}

			// 1) Is this a static field?
			foreach($fields[$fieldType]['static'] as $staticField => $fullFieldName) {
				if ($fieldName == $fullFieldName) {
					$fieldCache[$fieldType][$staticField][] = $fullFieldName;
					continue 2;
				}
			}

			// Localized and multiformat fields have a locale suffix.
			$locale = $fieldSuffix;
			// check for double locales
			if ($locale != 'txt' && ctype_upper($locale)) {
				$locale = array_pop($fieldNameParts) . '_' . $locale;
			}

			// 2) Is this a dynamic localized field?
			foreach($fields[$fieldType]['localized'] as $localizedField) {
				if (strpos($fieldName, $localizedField) === 0) {
					$fieldCache[$fieldType][$localizedField][] = $locale;
				}
			}

			// 3) Is this a dynamic multi-format field?
			foreach($fields[$fieldType]['multiformat'] as $multiformatField) {
				if (strpos($fieldName, $multiformatField) === 0) {
					// Identify the format of the field.
					$format = array_pop($fieldNameParts);

					// Add the field to the field cache.
					if (!isset($fieldCache[$fieldType][$multiformatField][$format])) {
						$fieldCache[$fieldType][$multiformatField][$format] = [];
					}
					$fieldCache[$fieldType][$multiformatField][$format][] = $locale;

					// Continue the outer loop.
					continue 2;
				}
			}
		}

		$cache->setEntireCache($fieldCache);
		return $fieldCache[$id];
	}

	/**
	 * Handle push indexing.
	 *
	 * This method pushes XML with index changes
	 * directly to the Solr data import handler for
	 * immediate processing.
	 *
	 * @param $articleXml string The XML with index changes
	 *  to be pushed to the Solr server.
	 * @param $batchCount integer The number of articles in
	 *  the XML list (i.e. the expected number of documents
	 *  to be indexed).
	 * @param $numDeleted integer The number of articles in
	 *  the XML list that are marked for deletion.
	 *
	 * @return integer The number of articles processed or
	 *  null if an error occurred.
	 *
	 *  After an error the method SolrWebService::getServiceMessage()
	 *  will return details of the error.
	 */
	function _pushIndexingCallback($articleXml, $batchCount, $numDeleted) {
		if ($batchCount > 0) {
			// Make a POST request with all articles in this batch.
			$url = $this->_getDihUrl() . '?command=full-import&clean=false';
			$result = $this->_makeRequest($url, $articleXml, 'POST');
			if (is_null($result)) return null;

			// Retrieve the number of successfully indexed articles.
			$numProcessed = $this->_getDocumentsProcessed($result);
			return $numProcessed;
		} else {
			// Nothing to update.
			return 0;
		}
	}

	/**
	 * Returns the solr DIH endpoint.
	 *
	 * @return string
	 */
	function _getDihUrl() {
		$dihUrl = $this->_solrServer . $this->_solrCore . '/dih';
		return $dihUrl;
	}

	/**
	 * Retrieve the number of indexed documents
	 * from a DIH response XML
	 * @param $result DOMXPath
	 * @return integer
	 */
	function _getDocumentsProcessed($result) {
		// Return the number of documents that were indexed.
		$processed = $result->query('/response/lst[@name="statusMessages"]/str[@name="Total Documents Processed"]');
		assert($processed->length == 1);
		$processed = $processed->item(0);

		$skipped= $result->query('/response/lst[@name="statusMessages"]/str[@name="Total Documents Skipped"]');
		$skipped = $skipped->item(0);
		$skipped = (integer)$skipped->textContent;
		$processed = (integer)$processed->textContent;
		$resultNode =  $processed + $skipped;
		return $resultNode;
	}
}
