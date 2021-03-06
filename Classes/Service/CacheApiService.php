<?php
namespace Etobi\Coreapi\Service;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2012 Georg Ringer <georg.ringer@cyberhouse.at>
 *  (c) 2014 Stefano Kowalke <blueduck@gmx.net>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Cache API service
 *
 * @author Georg Ringer <georg.ringer@cyberhouse.at>
 * @author Stefano Kowalke <blueduck@gmx.net>
 * @package Etobi\Coreapi\Service\SiteApiService
 */
class CacheApiService {

	const CACHE_DIRECTORY = 'typo3temp';

	/**
	 * @var \TYPO3\CMS\Core\DataHandling\DataHandler
	 */
	protected $dataHandler;

	/**
	 * @var \TYPO3\CMS\Extbase\Object\ObjectManager $objectManager
	 */
	protected $objectManager;

	/**
	 * @param \TYPO3\CMS\Core\DataHandling\DataHandler $dataHandler
	 *
	 * @return void
	 */
	public function injectDataHandler(\TYPO3\CMS\Core\DataHandling\DataHandler $dataHandler) {
		$this->dataHandler = $dataHandler;
	}

	/**
	 * @param \TYPO3\CMS\Extbase\Object\ObjectManager $objectManager
	 *
	 * @return void
	 */
	public function injectObjectManager(\TYPO3\CMS\Extbase\Object\ObjectManager $objectManager) {
		$this->objectManager = $objectManager;
	}


	/**
	 * Initialize the object.
	 *
	 * @return void
	 */
	public function initializeObject() {
		// Create a fake admin user
		$adminUser = $this->objectManager->get('TYPO3\\CMS\\Core\\Authentication\\BackendUserAuthentication');
		$adminUser->user['uid'] = $GLOBALS['BE_USER']->user['uid'];
		$adminUser->user['username'] = '_CLI_lowlevel';
		$adminUser->user['admin'] = 1;
		$adminUser->workspace = 0;

		$this->dataHandler->start(Array(), Array(), $adminUser);
	}

	/**
	 * Clear all caches.
	 *
	 * @param bool $hard
	 * @throws \Etobi\Coreapi\Service\Exception
	 * @return void
	 */
	public function clearAllCaches($hard = FALSE) {
		try {
			$this->assureCacheDirectoryIsWriteable();
		} catch (\UnexpectedValueException $e) {
		}
		!$hard ? $this->dataHandler->clear_cacheCmd('all') : $this->clearAllCachesHard();
	}

	/**
	 * @throws Exception
	 * @throws \UnexpectedValueException
	 * @return void
	 */
	public function assureCacheDirectoryIsWriteable() {
		$cacheDirectory = $this->getCacheDirectory();
		$recursiveDirectoryIterator = new \RecursiveDirectoryIterator($cacheDirectory);
		$iterator = new \RecursiveIteratorIterator($recursiveDirectoryIterator);
		foreach ($iterator AS $splFileInfo) {
			if ($splFileInfo->isWritable() === FALSE) {
				throw new Exception($cacheDirectory . ' not writeable ' . $splFileInfo->getRealpath(), 1433262208);
			}
		}
	}

	/**
	 * Clear the page cache.
	 *
	 * @return void
	 */
	public function clearPageCache() {
		$this->dataHandler->clear_cacheCmd('pages');
	}

	/**
	 * Clears the configuration cache.
	 *
	 * @return void
	 */
	public function clearConfigurationCache() {
		$this->dataHandler->clear_cacheCmd('temp_cached');
	}

	/**
	 * Clear the system cache
	 *
	 * @return void
	 */
	public function clearSystemCache() {
		$this->dataHandler->clear_cacheCmd('system');
	}

	/**
	 * Clears the opcode cache.
	 *
	 * @param string|NULL $fileAbsPath The file as absolute path to be cleared
	 *                                 or NULL to clear completely.
	 *
	 * @return void
	 */
	public function clearAllActiveOpcodeCache($fileAbsPath = NULL) {
		$this->clearAllActiveOpcodeCacheWrapper($fileAbsPath);
	}

	/**
	 * Clear all caches except the page cache.
	 * This is especially useful on big sites when you can't
	 * just drop the page cache.
	 *
	 * @return array with list of cleared caches
	 */
	public function clearAllExceptPageCache() {
		$out = array();
		$cacheKeys = array_keys($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']);
		$ignoredCaches = array('cache_pages', 'cache_pagesection');

		$toBeFlushed = array_diff($cacheKeys, $ignoredCaches);

		/** @var \TYPO3\CMS\Core\Cache\CacheManager $cacheManager */
		$cacheManager = $GLOBALS['typo3CacheManager'];
		foreach ($cacheKeys as $cacheKey) {
			if ($cacheManager->hasCache($cacheKey)) {
				$out[] = $cacheKey;
				$singleCache = $cacheManager->getCache($cacheKey);
				$singleCache->flush();
			}
		}

		return $toBeFlushed;
	}

	/**
	 * Clears the opcode cache. This just wraps the static call for testing purposes.
	 *
	 * @param string|NULL $fileAbsPath The file as absolute path to be cleared
	 *                                 or NULL to clear completely.
	 *
	 * @return void
	 */
	protected function clearAllActiveOpcodeCacheWrapper($fileAbsPath) {
		if (version_compare(TYPO3_version, '7.4.0', '<')) {
			\TYPO3\CMS\Core\Utility\OpcodeCacheUtility::clearAllActive($fileAbsPath);
		} else {
			\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\CMS\Core\Service\OpcodeCacheService')->clearAllActive($fileAbsPath);
		}
	}

	/**
	 * This clear cache implementation follows a pretty brutal approach.
	 * Goal is to reliably get rid of cache entries, even if some broken
	 * extension is loaded that would kill the backend 'clear cache' action.
	 *
	 * Therefor this method "knows" implementation details of the cache
	 * framework and uses them to clear all file based cache (typo3temp/Cache)
	 * and database caches (tables prefixed with cf_) manually.
	 *
	 * This is the change to the InstallTool->clearAll() method
	 * This will not happened (so we do not respect different Cache Backends)
	 *
	 * After that ext_tables and ext_localconf of extensions are loaded, those
	 * may register additional caches in the caching framework with different
	 * backend, and will then clear them with the usual flush() method.
	 *
	 * @return void
	 * @see \TYPO3\CMS\Install\Service\ClearCacheService
	 */
	protected function clearAllCachesHard() {
		$gitDummyFileExists = file_exists($this->getCacheDirectory() . '/.gitdummy');
		GeneralUtility::flushDirectory($this->getCacheDirectory(), TRUE, TRUE);
		if ($gitDummyFileExists === TRUE) {
			touch($this->getCacheDirectory() . '/.gitdummy');
		}

		$database = $this->getDatabaseConnection();
		$tables = $database->admin_get_tables();
		foreach ($tables as $table) {
			$tableName = $table['Name'];
			if (substr($tableName, 0, 3) === 'cf_' || substr($tableName, 0, 6) === 'cache_') {
				$database->exec_TRUNCATEquery($tableName);
			}
		}
	}

	/**
	 * @return \TYPO3\CMS\Core\Database\DatabaseConnection
	 */
	protected function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}

	/**
	 * @return string
	 */
	protected function getCacheDirectory() {
		return PATH_site . self::CACHE_DIRECTORY;
	}
}
