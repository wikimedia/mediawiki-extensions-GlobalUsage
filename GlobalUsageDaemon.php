<?php

class GlobalUsageDaemon {
	// This daemon supports updating from multiple wikis

	// Array of wiki => timestamp pairs
	public $timestamps;
	// Array of database key => database pairs
	private $databases;
	// Array of localized namespaces
	private $namespaces;
	// Location of the log file
	private $log;
	// Stderr pointer
	private $stderr;
	// Array of wikis containing config settings
	private $wikiList;

	public function __construct($log, $wikiList, $silent = false) {
		$this->databases = array();
		$this->timestamps = array();
		$this->namespaces = array();
		$this->log = $log;
		$this->wikiList = $wikiList;
		
		if (!$silent)
			$this->stderr = fopen('php://stderr', 'w');
		else
			$this->stderr = null;
		
		if (($fp = fopen($this->log, 'r')) !== false) {
			flock($fp, LOCK_EX);
			while (!feof($fp)) {
				$line = fgets($fp);
				if (strpos($line, "\t") !== false) {
					list($lw, $lt) = explode("\t", $line, 2);
					$this->timestamps[$lw] = trim($lt);
				}
			}
			fclose($fp);
		}
		$this->debug("Read previous data from {$log}");
		$this->debug('Position is defined for the following wikis: '.
			implode(', ', array_keys($this->timestamps)));
	}

	public function debug($string) {
		if ($this->stderr) fwrite($this->stderr, "{$string}\n");
	}

	/* 
	* Populate the globalimagelinks table from the local imagelinks
	*/
	public function populateGlobalUsage($wiki, $interval, $throttle = 1000000, $maxLag) {
		$this->debug("Populating globalimagelinks on {$wiki}");

		if (!isset($this->namespaces[$wiki]))
			$this->fetchNamespaces($wiki);
		$namespaces = $this->namespaces[$wiki];
		
		$dbw = GlobalUsage::getDatabase(DB_MASTER);
		$dbw->immediateBegin();
		$dbw->delete('globalimagelinks', array('gil_wiki' => $wiki), __METHOD__);

		$dbr = $this->getDatabase($wiki);
		
		// Account for slave lag
		$res = $dbr->select('recentchanges', 'MAX(rc_timestamp) AS timestamp');
		$row = $res->fetchRow();
		$timestamp = substr(substr($row['timestamp'], 0, 14 - $interval).
			'00000000000000', 0, 14);
		$res->free();
		
		$offset = 0;
		$limit = 2000;
		do {
			$loopStart = microtime(true);
			
			$query = 'SELECT '.
				'page_id, page_namespace, page_title, il_to, img_name IS NOT NULL AS is_local '.
				'FROM '.$dbr->tableName('page').', '.$dbr->tableName('imagelinks').' '.
				'LEFT JOIN '.$dbr->tableName('image').' ON il_to = img_name '.
				'WHERE page_id = il_from '.
				"LIMIT {$offset},{$limit}";
			$res = $dbr->query($query, __METHOD__);
			
			$count = 0;
			$rows = array();
			while ($row = $res->fetchRow()) {
				$count++;
				$rows[] = array(
					'gil_wiki' => $wiki,
					'gil_page' => $row['page_id'],
					'gil_page_namespace' => $namespaces[$row['page_namespace']],
					'gil_page_title' => $row['page_title'],
					'gil_to' => $row['il_to'],
					'gil_is_local' => $row['is_local']
				);
			}
			$res->free();
				
			$dbw->insert( 'globalimagelinks', $rows, __METHOD__, 'IGNORE' );

			$offset += $count;
			
			$timeTaken = microtime(true) - $loopStart;
			$rps = $count / $timeTaken;
			$this->debug("Inserted {$count} rows in {$timeTaken} seconds; {$rps} rows per second");
			if ($rps > $throttle) {
				$sleepTime = ($rps / $throttle - 1) * $timeTaken;
				$this->debug("Throttled {$sleepTime} seconds");
				sleep($sleepTime);
			}
			if ($maxLag) {
				$lb = wfGetLB($wiki);
				do {
					list($host, $lag) = $lb->getMaxLag();
					if ($lag > $maxLag) {
						$this->debug("Waiting for {$host}; lagged {$lag} seconds");
						sleep($lag - $maxLag);
					}
				} while ($lag > $maxLag);
			}
		} while ($count == $limit);
		$dbw->immediateCommit();
		
		$this->setTimestamp($wiki, $timestamp);
	}
	
	/*
	* Populate the globalimagelinks table from the recentchanges
	*/
	public function processRecentChanges($wiki, $interval = 2) {
		$dbr = $this->getDatabase($wiki);
		$dbw = GlobalUsage::getDatabase(DB_MASTER);
		
		$tables = array(
			'img' => $dbr->tableName('image'),
			'il' => $dbr->tableName('imagelinks'),
			'log' => $dbr->tableName('logging'),
			'page' => $dbr->tableName('page'),
			'rc' => $dbr->tableName('recentchanges'),
		);
		
		// Get timestamp
		$timestamp = substr($this->timestamps[$wiki], 0, 14 - $interval);
		
		$dbw->immediateBegin();
			
		// Update links on all recentchanges
		$query = 'SELECT DISTINCT '.
			'page_id AS id, rc_namespace AS ns, rc_title AS title '.
			"FROM {$tables['rc']}, {$tables['page']} ".
			'WHERE page_namespace = rc_namespace AND page_title = rc_title '.
			'AND rc_namespace <> -1 AND '.
			"rc_timestamp LIKE '{$timestamp}%'";
			
		$res = $dbr->query($query, __METHOD__);
		
		$rows = array();
		while($row = $res->fetchRow())
			$rows[] = $row;
		$res->free();
		$this->processRows($rows, $wiki, $dbr, $dbw);
			
		// Update links on deletion or undeletion of an article
		$query = 'SELECT '.
			'page_id AS id, log_namespace AS ns, log_title AS title, '.
			'page_id IS NOT NULL AS is_local '.
			"FROM {$tables['log']} ".
			"LEFT JOIN {$tables['page']} ON ".
			'page_namespace = log_namespace AND '.
			'page_title = log_title '.
			'WHERE log_action = "delete" AND '.
			"log_timestamp LIKE '{$timestamp}%'";
		
		$res = $dbr->query($query, __METHOD__);
			
		$rows = array();
		while($row = $res->fetchRow()) {
			if ($row['is_local']) {
				$rows[] = $row;
			} else {
				$dbw->delete( 'globalimagelinks', array(
					'gil_wiki' => $wiki,
					'gil_page' => $row['id'],
					), __METHOD__);
			}
		}
		$res->free();
		$this->processRows($rows, $wiki, $dbr, $dbw);
		
		// Set the is_local flag on images
		$query = 'SELECT DISTINCT '.
			'log_title, img_name IS NOT NULL AS is_local '.
			"FROM {$tables['log']}, {$tables['il']} ".
			"LEFT JOIN {$tables['img']} ON il_to = img_name ".
			'WHERE log_namespace = 6 AND log_type IN ("upload", "delete") '.
			"AND log_timestamp LIKE '{$timestamp}%'";
		$res = $dbr->query($query, __METHOD__);
			
		while($row = $res->fetchRow()) {
			$dbw->update( 'globalimagelinks', 
				array( 'gil_is_local' => $row['is_local'] ), 
				array(
					'gil_wiki' => $wiki,
					'gil_to' => $row['log_title']
				), 
				__METHOD__ );
		}
		$res->free();
			
		// Update titles on page move
		$res = $dbr->select('logging', 
			array('log_namespace', 'log_title', 'log_params'),
			"log_type = 'move' AND log_timestamp LIKE '{$timestamp}%'",
			__METHOD__);
				
		while($row = $res->fetchRow()) {
			$namespace = '';
			$title = $row['log_params'];
			if (strpos($row['log_params'], ':') !== false) {
				$new_title = explode(':', $row['log_params'], 2);
				if (in_array($new_title[0], $this->namespaces[$wiki])) {
					list($namespace, $title) = $new_title;
				}
			}
			$dbw->update( 'globalimagelinks', array(
				'gil_page_namespace' => $namespace,
				'gil_page_title' => $title
			), array(
				'gil_wiki' => $wiki,
				'gil_page_namespace' => $row['log_namespace'],
				'gil_page_title' => $row['log_title']
			), __METHOD__ );
		}
		$res->free();
			
		$dbw->immediateCommit();
			
		// Set new timestamp
		$newTs = wfTimestamp(TS_MW, $this->incrementTimestamp(
			$this->timestamps[$wiki], $interval));
		$this->setTimestamp($wiki, $newTs);
		
		// Return when this function should be called again
		return $this->incrementTimestamp($newTs, $interval);
	}
	
	/* 
	* Call doUpdate
	*/
	private function processRows($rows, $wiki, $dbr, $dbw) {
		if (count($rows)) {
			foreach ($rows as $row)
				GlobalUsage::doUpdate($row['id'], $wiki,
					$row['ns'], $row['title'], $dbr, $dbw);
		}
	}
	
	/*
	* Get namespace names
	*/
	private function fetchNamespaces($wiki) {
		global $wgContLang;
		if ($wiki == GlobalUsage::getLocalInterwiki()) {
			$this->namespaces[$wiki] = $wgContLang->getFormattedNamespaces();
		} else {
			// Not the current wiki, need to fetch using the API
			$this->debug("Fetching namespaces from external wiki {$wiki}");
			
			if (!isset($this->wikiList[$wiki])) {
				// Raise error
			}
			$address = $this->wikiList[$wiki];
			$address .= '?action=query&meta=siteinfo&siprop=namespaces&format=php';
			
			$curl = curl_init($address);
			curl_setopt($curl, CURLOPT_USERAGENT, 'GlobalUsage/1.0');
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			$data = unserialize(curl_exec($curl));
			curl_close($curl);
			
			$this->namespaces[$wiki] = array();
			foreach ($data['query']['namespaces'] as $id => $value) 
				$this->namespaces[$wiki][$id] = $value['*'];
		}
	}
	
	/* 
	* Get database object for reading
	*/
	private function getDatabase($wiki) {
		if ($wiki == GlobalUsage::getLocalInterwiki())
			return wfGetDB(DB_SLAVE);
		else
			return wfGetDB(DB_SLAVE, array(), $wiki);
	}
	
	/* 
	* Save timestamp to a logfile to continue after
	*/
	private function setTimestamp($wiki, $timestamp) {
		$written = false;
		if (($fp = fopen($this->log, 'r+')) === false) {
			// Raise an error
		}
		
		flock($fp, LOCK_EX);
		fseek($fp, 0, SEEK_SET);
		while (!feof($fp)) {
			$line = fgets($fp);
			if (strpos($line, "\t") !== false) {
				list($lw, $lt) = explode("\t", $line, 2);
				if ($lw == $wiki) {
					fseek($fp, ftell($fp) - 15, SEEK_SET);
					fwrite($fp, $timestamp);
					$written = true;
				}
			}
		}
		if (!$written) fwrite($fp, "{$wiki}\t{$timestamp}\n");
		fclose($fp);
		
		$this->timestamps[$wiki] = $timestamp;
	}
	private function incrementTimestamp($timestamp, $interval) {
		$timestamp = (string)((int)$timestamp + pow(10, $interval));
		return gmmktime(
			substr($timestamp, 8, 2),
			substr($timestamp, 10, 2),
			substr($timestamp, 12, 2),
			substr($timestamp, 4, 2),
			substr($timestamp, 6, 2),
			substr($timestamp, 0, 4)
		);
	}
	
	public function runLocalDaemon($wiki, $interval) {
		$this->debug("Running local daemon on {$wiki}");
		
		// Fetch namespaces
		if (!isset($this->namespaces[$wiki]))
			$this->fetchNamespaces($wiki);
		$dbr = $this->getDatabase($wiki);
			
		while (true) {
			$waitUntil = $this->processRecentChanges($wiki, $interval);
			while ($waitUntil > time() - $dbr->getLag()) {
				$sleepTime = max($waitUntil + $dbr->getLag() - time(), 0);
				$this->debug("Sleeping {$sleepTime} seconds: ".
					'need to wait until '.wfTimestamp(TS_MW, $waitUntil).
					'; now is '.wfTimestamp(TS_MW));
				sleep($sleepTime);
			}
		}
	}
	public function runDaemon($interval) {
		$waitUntil = array();
		
		foreach ($this->wikiList as $wiki => $info)
			$waitUntil[$wiki] = 0;
		
		$this->debug("Running GlobalUsage daemon on the following wikis: ".
			implode(', ', array_keys($waitUntil)));
		while (true) {
			// Sort by time
			asort($waitUntil);
			reset($waitUntil);
			
			$dbr = $this->getDatabase(key($waitUntil));
			while (current($waitUntil) > time() - $dbr->getLag()) {
				$sleepTime = max(current($waitUntil) + $dbr->getLag() - time(), 0);
				$this->debug("Sleeping {$sleepTime} seconds: ".
					'need to wait until '.
					wfTimestamp(TS_MW, current($waitUntil)).
					'; now is '.wfTimestamp(TS_MW));
				sleep($sleepTime);
			}
			
			$wiki = key($waitUntil);
			
			// Fetch namespaces
			if (!isset($this->namespaces[$wiki]))
				$this->fetchNamespaces($wiki);
			
			$this->debug("Processing recentchanges for {$wiki}");
			$waitUntil[$wiki] = $this->processRecentChanges($wiki, $interval);
		}
	}
}
