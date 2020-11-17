<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Page
 */
namespace MediaWiki\Page;

use IBufferingStatsdDataFactory;
use InvalidArgumentException;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionRenderer;
use ParserCache;
use ParserOptions;
use ParserOutput;
use PoolWorkArticleView;
use PoolWorkArticleViewCurrent;
use Status;
use Wikimedia\Rdbms\ILBFactory;
use WikiPage;

/**
 * Service for getting rendered output of a given page.
 * This is a high level service, encapsulating concerns like caching
 * and stampede protection via PoolCounter.
 *
 * @since 1.36
 *
 * @unstable Extensions should use WikiPage::getParserOutput until this class has settled down.
 */
class ParserOutputAccess {

	/**
	 * @var int Do not check the cache before parsing (force parse)
	 */
	public const OPT_NO_CHECK_CACHE = 1;

	/** @var int Alias for NO_CHECK_CACHE */
	public const OPT_FORCE_PARSE = self::OPT_NO_CHECK_CACHE;

	/**
	 * @var int Do not update the cache after parsing.
	 *      Private because it does not make sense to be called separately.
	 */
	private const OPT_NO_UPDATE_CACHE = 2;

	/**
	 * @var int Bypass audience check for deleted/suppressed revisions.
	 *      The caller is responsible for ensuring that unauthorized access is prevented.
	 *      If not set, output generation will fail if the revision is not public.
	 */
	public const OPT_NO_AUDIENCE_CHECK = 4;

	/**
	 * @var int Do not check the cache before parsing,
	 *      and do not update the cache after parsing (not cacheable).
	 */
	public const OPT_NO_CACHE = self::OPT_NO_UPDATE_CACHE | self::OPT_NO_CHECK_CACHE;

	/** @var ParserCache */
	private $primaryCache;

	/** @var RevisionRenderer */
	private $revisionRenderer;

	/** @var IBufferingStatsdDataFactory */
	private $statsDataFactory;

	/** @var ILBFactory */
	private $lbFactory;

	/**
	 * @param ParserCache $primaryCache
	 * @param RevisionRenderer $revisionRenderer
	 * @param IBufferingStatsdDataFactory $statsDataFactory
	 * @param ILBFactory $lbFactory
	 */
	public function __construct(
		ParserCache $primaryCache,
		RevisionRenderer $revisionRenderer,
		IBufferingStatsdDataFactory $statsDataFactory,
		ILBFactory $lbFactory
	) {
		$this->primaryCache = $primaryCache;
		$this->revisionRenderer = $revisionRenderer;
		$this->statsDataFactory = $statsDataFactory;
		$this->lbFactory = $lbFactory;
	}

	/**
	 * Use a cache?
	 *
	 * @param WikiPage $page
	 * @param ParserOptions $parserOptions ParserOptions to check
	 * @param RevisionRecord|null $rev
	 *
	 * @return bool
	 */
	private function shouldUseCache(
		WikiPage $page,
		ParserOptions $parserOptions,
		?RevisionRecord $rev
	) {
		if ( $rev && !$rev->getId() ) {
			// The revision isn't from the database, so the output can't safely be cached.
			return false;
		}

		// NOTE: Keep in sync with ParserWikiPage::shouldCheckParserCache().
		// NOTE: when we allow caching of old revisions in the future,
		//       we must not allow caching of deleted revisions.
		$oldId = $rev ? $rev->getId() : 0;
		return $parserOptions->getStubThreshold() == 0
			&& $page->exists()
			&& ( $oldId === null || $oldId === 0 || $oldId === $page->getLatest() )
			&& $page->getContentHandler()->isParserCacheSupported();
	}

	/**
	 * Returns the rendered output for the given page if it is present in the cache.
	 *
	 * @param WikiPage $page
	 * @param ParserOptions $parserOptions
	 * @param RevisionRecord|null $revision
	 * @param int $options Bitfield using the OPT_XXX constants
	 *
	 * @return ParserOutput|null
	 */
	public function getCachedParserOutput(
		WikiPage $page,
		ParserOptions $parserOptions,
		?RevisionRecord $revision = null,
		int $options = 0
	): ?ParserOutput {
		if ( $parserOptions->getStubThreshold() ) {
			$this->statsDataFactory->updateCount( 'pcache.miss.stub', 1 );
			return null;
		}

		if ( !$this->shouldUseCache( $page, $parserOptions, $revision ) ) {
			return null;
		}

		$output = $this->primaryCache->get( $page, $parserOptions );

		return $output ?: null; // convert false to null
	}

	/**
	 * Returns the rendered output for the given page.
	 * Caching and concurrency control is applied.
	 *
	 * @param WikiPage $page
	 * @param ParserOptions $parserOptions
	 * @param RevisionRecord|null $revision
	 * @param int $options Bitfield using the OPT_XXX constants
	 *
	 * @return Status containing a ParserOutput is no error occurred.
	 *         Well known errors and warnings include the following messages:
	 *         - 'view-pool-dirty-output' (warning) The output is dirty (from a stale cache entry).
	 *         - 'view-pool-contention' (warning) Dirty output was returned immediately instead of
	 *           waiting to acquire a work lock (when "fast stale" mode is enabled in PoolCounter).
	 *         - 'view-pool-timeout' (warning) Dirty output was returned after failing to acquire
	 *           a work lock (got QUEUE_FULL or TIMEOUT from PoolCounter).
	 *         - 'pool-queuefull' (error) unable to acquire work lock, and no cached content found.
	 *         - 'pool-timeout' (error) unable to acquire work lock, and no cached content found.
	 *         - 'pool-servererror' (error) PoolCounterWork failed due to a lock service error.
	 *         - 'pool-unknownerror' (error) PoolCounterWork failed for an unknown reason.
	 *         - 'nopagetext' (error) The page does not exist
	 */
	public function getParserOutput(
		WikiPage $page,
		ParserOptions $parserOptions,
		?RevisionRecord $revision = null,
		int $options = 0
	): Status {
		$error = $this->checkPreconditions( $page, $parserOptions, $revision, $options );
		if ( $error ) {
			return $error;
		}

		if ( !( $options & self::OPT_NO_CHECK_CACHE ) ) {
			$output = $this->getCachedParserOutput( $page, $parserOptions, $revision );
			if ( $output ) {
				return Status::newGood( $output );
			}
		}

		if ( !$revision ) {
			$revision = $page->getRevisionRecord();

			if ( !$revision ) {
				return Status::newFatal(
					'missing-revision',
					$page->getLatest()
				);
			}
		}

		$work = $this->newPoolWorkArticleView( $page, $parserOptions, $revision, $options );
		$work->execute();
		$output = $work->getParserOutput();

		$status = Status::newGood();
		if ( $work->getError() ) {
			$status->merge( $work->getError() );
		}

		if ( !$output && $status->isOK() ) {
			// TODO: PoolWorkArticle should properly report errors (T267610)
			$status->fatal( 'pool-errorunknown' );
		}

		if ( $output && $status->isOK() ) {
			$status->setResult( true, $output );
		}

		if ( $status->isOK() && $work->getIsDirty() ) {
			$staleReason = $work->getIsFastStale()
				? 'view-pool-contention' : 'view-pool-overload';

			$status->warning( 'view-pool-dirty-output' );
			$status->warning( $staleReason );
		}

		return $status;
	}

	/**
	 * @param WikiPage $page
	 * @param ParserOptions $parserOptions
	 * @param RevisionRecord|null $revision
	 * @param int $options
	 *
	 * @return Status|null
	 */
	private function checkPreconditions(
		WikiPage $page,
		ParserOptions $parserOptions,
		?RevisionRecord $revision = null,
		int $options = 0
	): ?Status {
		if ( !$page->exists() ) {
			return Status::newFatal( 'nopagetext' );
		}

		if ( !( $options & self::OPT_NO_UPDATE_CACHE ) ) {
			if ( !$parserOptions->isSafeToCache() ) {
				throw new InvalidArgumentException(
					'The supplied ParserOptions are not safe to cache. Use NO_CACHE.'
				);
			}

			if ( $revision && !$revision->getId() ) {
				throw new InvalidArgumentException(
					'The revision does not have a known ID. Use NO_CACHE.'
				);
			}
		}

		if ( $revision && $revision->getPageId() !== $page->getId() ) {
			throw new InvalidArgumentException(
				'The revision does not belong to the given page.'
			);
		}

		if ( $revision && !( $options & self::OPT_NO_AUDIENCE_CHECK ) ) {
			// NOTE: If per-user checks are desired, the caller should perform them and
			//       then set OPT_NO_AUDIENCE_CHECK if they passed.
			if ( !$revision->audienceCan( RevisionRecord::DELETED_TEXT, RevisionRecord::FOR_PUBLIC ) ) {
				return Status::newFatal(
					'missing-revision-permission',
					$revision->getId(),
					$revision->getTimestamp(),
					$page->getTitle()->getPrefixedDBkey()
				);
			}
		}

		return null;
	}

	/**
	 * @param WikiPage $page
	 * @param ParserOptions $parserOptions
	 * @param RevisionRecord|null $revision
	 * @param int $options
	 *
	 * @return PoolWorkArticleView
	 */
	private function newPoolWorkArticleView(
		WikiPage $page,
		ParserOptions $parserOptions,
		RevisionRecord $revision,
		int $options
	): PoolWorkArticleView {
		if ( $options & self::OPT_NO_UPDATE_CACHE ) {
			$useCache = false;
		} else {
			$useCache = $this->shouldUseCache( $page, $parserOptions, $revision );
		}

		$parserCacheMetadata = $this->primaryCache->getMetadata( $page );
		$cacheKey = $this->primaryCache->makeParserOutputKey( $page, $parserOptions,
			$parserCacheMetadata ? $parserCacheMetadata->getUsedOptions() : null
		);

		$workKey = $cacheKey . ':revid:' . $revision->getId();

		if ( $useCache ) {
			$workKey .= ':current';
			$work = new PoolWorkArticleViewCurrent(
				$workKey,
				$page,
				$revision,
				$parserOptions,
				$this->revisionRenderer,
				$this->primaryCache,
				$this->lbFactory
			);
		} else {
			$workKey .= ':uncached';
			$work = new PoolWorkArticleView(
				$workKey,
				$revision,
				$parserOptions,
				$this->revisionRenderer
			);
		}

		return $work;
	}

}
