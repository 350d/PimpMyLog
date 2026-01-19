<?php

class LogParser
{
	/**
	 * Read a log file and return an array of logs with metadata
	 *
	 * @param   string  $regex         A regex to match each line
	 * @param   array   $match         An array of matchers
	 * @param   array   $types         An array of matchers types
	 * @param   string  $tz            The wanted timezone to translate matchers with a date type
	 * @param   integer $wanted_lines  the count of wanted lines to be returned
	 * @param   array   $exclude       An array of exclusion matchers tokens
	 * @param   string  $file_path     the file path
	 * @param   integer $start_offset  the offset where to begin to parse file
	 * @param   integer $start_from    the position from where the offset is taken
	 * @param   boolean $load_more     loadmore mode : if true, we do not try to guess the previous line
	 * @param   string  $old_lastline  The fingerprint of the last known line (previous call)
	 * @param   boolean $multiline     Whether the parser should understand non understandable lines as multi lines of
	 *                                 a field
	 * @param   string  $search        A search expression
	 * @param   integer $data_to_parse The maximum count of bytes to read a new line (basically the difference between
	 *                                 the previous scanned file size and the current one)
	 * @param   boolean $full          Whether the log file should be loaded from scratch
	 * @param   [type]   $max_search_log_time  The maximum duration in s to parse lines
	 * @param   string  $block_start   A regex pattern or string to identify the start of a log block (for block-based parsing)
	 *
	 * @return  [type]                        [description]
	 */
	public static function getNewLines( $regex , $match , $types , $tz , $wanted_lines , $exclude , $file_path , $start_offset , $start_from , $load_more , $old_lastline , $multiline , $search , $data_to_parse , $full , $max_search_log_time , $block_start = '' )
	{

		$fl = fopen( $file_path , "r" );
		if ( $fl === false )
		{
			return '1';
		}

		$logs            = array();
		$start           = microtime( true );
		$regsearch       = false;
		$found           = false;
		$bytes           = 0;
		$skip            = 0;
		$error           = 0;
		$abort           = false;
		$file_lastline   = '';
		$search_lastline = true;
		$buffer          = array();

		/*
		|--------------------------------------------------------------------------
		| Block-based parsing mode
		|--------------------------------------------------------------------------
		| If block_start is specified, use block-based parsing instead of line-based
		|
		*/
		if ( $block_start !== '' )
		{
			return self::getNewLinesBlockMode( $regex , $match , $types , $tz , $wanted_lines , $exclude , $file_path , $start_offset , $start_from , $load_more , $old_lastline , $search , $data_to_parse , $full , $max_search_log_time , $block_start );
		}

		/*
		|--------------------------------------------------------------------------
		| Last modification time and size for this log file
		|--------------------------------------------------------------------------
		|
		*/
		if ( version_compare( PHP_VERSION , '5.3.0' ) >= 0 )
		{
			$filem = new DateTime();
			$filem->setTimestamp( filemtime( $file_path ) );
		}
		else
		{
			$filem = new DateTime( "@" . filemtime( $file_path ) );
		}
		if ( ! is_null( $tz ) )
		{
			$filem->setTimezone( new DateTimeZone( $tz ) );
		}
		$filemu   = $filem->format( 'U' );
		$filem    = $filem->format( 'Y/m/d H:i:s' );
		$filesize = filesize( $file_path );

		/*
		|--------------------------------------------------------------------------
		| Try to guess if the seach expression is a regexp or not
		|--------------------------------------------------------------------------
		|
		*/
		if ( $search !== '' )
		{
			$test      = @preg_match( $search , 'this is just a test !' );
			$regsearch = ( $test === false ) ? false : true;
		}

		/*
		|--------------------------------------------------------------------------
		| Read file
		|--------------------------------------------------------------------------
		|
		*/
		for ( $x_pos = $start_offset , $ln = 0 , $line = '' , $still = true ; $still ; $x_pos-- )
		{

			// We have reached the beginning of file
			// Validate the previous read chars by simulating a NL
			if ( fseek( $fl , $x_pos , $start_from ) === -1 )
			{
				$still = false;
				$char  = "\n";
			}

			// Read a char on a log line
			else
			{
				$char = fgetc( $fl );
			}


			// If the read char if a NL, we need to manage the previous buffered chars as a line
			if ( $char === "\n" )
			{

				// Copy the log line as an utf8 line
				$deal = ( mb_check_encoding( $line , 'UTF-8' ) === false ) ? utf8_encode( $line ) : $line;

				// Reset the line for future reads
				$line = '';

				// Manage the new line
				if ( $deal !== '' )
				{

					// Get the last line of the file to compute the hash of this line
					if ( $search_lastline === true )
					{
						$file_lastline   = sha1( $deal );
						$search_lastline = false;
					}

					// Check if we have reach the previous line in normal mode
					// We don't have to manage this when loading older logs
					if ( $load_more === false )
					{

						// We have reach the count bytes to manage
						if ( $bytes > $data_to_parse )
						{

							// So the new line should be the last line of the previous time
							if ( $old_lastline !== sha1( $deal ) )
							{

								// This is not the case, so the file has been rotated and the new log file is bigger than the previous time
								// So we have to continue computing to find the user wanted count of lines (and alert user about the file change)
								$logs[ 'notice' ] = 1;
								$full             = true;
							}

							// Ok lines are the same so just stop and return new found lines
							else
							{
								break;
							}
						}
					}

					// Parse the new line
					$log = self::parseLine( $regex , $match , $deal , $types , $tz );

					// The line has been successfully parsed by the parser (user regex ok)
					if ( is_array( $log ) )
					{

						// We will get this log by default but search can exclude this log later
						$return_log = true;

						// If we previously have parsed some multilines, we need now to include them
						$last_field_append = ( count( $buffer ) > 0 ) ? "\n" . implode( "\n" , array_reverse( $buffer ) ) : '';;
						$buffer = array();

						foreach ( $log as $key => $value )
						{

							// Manage multilines
							if ( $key === $multiline )
							{
								$value .= $last_field_append;
								$deal .= $last_field_append;
								$log[ $key ] = $value;
							}

							// Is this log excluded ?
							if ( ( isset( $exclude[ $key ] ) ) && ( is_array( $exclude[ $key ] ) ) )
							{
								foreach ( $exclude[ $key ] as $ekey => $reg )
								{
									try
									{
										if ( preg_match( $reg , $value ) )
										{
											$return_log = false;
											break 2;
										}
									}
									catch ( Exception $e )
									{
									}
								}
							}
						}

						// This line should be skipped because it has been excluded by user configuration
						if ( $return_log === false )
						{
							$skip++;
						}

						// Filter now this line by search
						else
						{

							if ( ! empty( $search ) )
							{

								// Regex
								if ( $regsearch )
								{
									$return_log = preg_match( $search , $deal . $last_field_append );
									if ( $return_log === 0 )
									{
										$return_log = false;
									}
								}

								// Simple search
								else
								{
									$return_log = strpos( $deal . $last_field_append , $search );
								}
							}

							// Search excludes this line
							if ( $return_log === false )
							{
								$skip++;
							}

							// Search includes this line
							else
							{
								$found            = true;
								$log[ 'pml' ]     = $deal . $last_field_append;
								$log[ 'pmlo' ]    = ftell( $fl );
								$logs[ 'logs' ][] = $log;
								$ln++;
							}
						}
					}

					// The line has not been successfully parsed by the parser but multiline feature is enabled so we treat this line as a multiline
					elseif ( $multiline !== '' )
					{
						$buffer[] = $deal;
					}

					// No multiline feature and unknown line : add this line as an error
					else
					{
						$error++;
					}

					// Break if we have found the wanted count of logs
					if ( $ln >= $wanted_lines )
					{
						break;
					}
				}

				// Break if time computing is too high
				if ( microtime( true ) - $start > $max_search_log_time )
				{
					$abort = true;
					break;
				}

				// continue directly without keeping the \n
				continue;
			}

			// Prepend the read char to the previous buffered chars
			$line = $char . $line;
			$bytes++;
		}

		// We need to store this value for load more when a search is active
		// The last searched line to display os certainly not the first line of the file
		// So if the value of $last_parsed_offset is 1, even if the last displayed line is not at offset 0 or 1, we must disable the Load More button
		$last_parsed_offset = ftell( $fl );

		fclose( $fl );

		/*
		|--------------------------------------------------------------------------
		| Return
		|--------------------------------------------------------------------------
		|
		*/
		$logs[ 'found' ]       = $found;
		$logs[ 'abort' ]       = $abort;
		$logs[ 'regsearch' ]   = $regsearch;
		$logs[ 'search' ]      = $search;
		$logs[ 'full' ]        = $full;
		$logs[ 'lpo' ]         = $last_parsed_offset;
		$logs[ 'count' ]       = $ln;
		$logs[ 'bytes' ]       = $bytes;
		$logs[ 'skiplines' ]   = $skip;
		$logs[ 'errorlines' ]  = $error;
		$logs[ 'fingerprint' ] = md5( serialize( @$logs[ 'logs' ] ) ); // Used to avoid notification on full refresh when nothing has finally changed
		$logs[ 'lastline' ]    = $file_lastline;
		$logs[ 'duration' ]    = (int)( ( microtime( true ) - $start ) * 1000 );
		$logs[ 'filesize' ]    = $filesize;
		$logs[ 'filemodif' ]   = $filem;
		$logs[ 'filemodifu' ]  = $filemu;

		return $logs;
	}


	/**
	 * Read lines from the bottom of giver file
	 *
	 * @param   string  $file  the file path
	 * @param   integer $count the count of wanted lines
	 *
	 * @return  array            an array of read lines or false of fiel error
	 */
	public static function getLinesFromBottom( $file , $count = 1 )
	{
		$fl    = @fopen( $file , "r" );
		$lines = array();
		$bytes = 0;

		if ( $fl === false )
		{
			return false;
		}

		$count = max( 1 , (int)$count );

		for ( $x_pos = 0 , $ln = 0 , $line = '' , $still = true ; $still ; $x_pos-- )
		{

			if ( fseek( $fl , $x_pos , SEEK_END ) === -1 )
			{
				$still = false;
				$char  = "\n";
			}
			else
			{
				$char = fgetc( $fl );
			}

			if ( $char === "\n" )
			{

				$deal = utf8_encode( $line );
				$line = '';

				if ( $deal !== '' )
				{
					$lines[] = $deal;
					$count--;
					if ( $count === 0 )
					{
						$still = false;
					}
				}

				// continue directly without keeping the \n
				continue;
			}
			$line = $char . $line;
			$bytes++;
		}

		fclose( $fl );

		return $lines;
	}


	/**
	 * A line of log parser
	 *
	 * @param string $regex The regex which describes the user log format
	 * @param array  $match An array which links internal tokens to regex matches
	 * @param string $log   The text log
	 * @param string $types A array of types for fields
	 * @param string $tz    A time zone identifier
	 *
	 * @return  mixed             An array where keys are internal tokens and values the corresponding values extracted
	 *                            from the log file. Or false if line is not matchable.
	 */
	public static function parseLine( $regex , $match , $log , $types , $tz = null )
	{
		// If line is non matchable, return
		preg_match_all( $regex , $log , $out , PREG_PATTERN_ORDER );
		if ( @count( $out[ 0 ] ) === 0 )
		{
			return false;
		}

		$result    = array();
		$timestamp = 0;

		foreach ( $match as $token => $key )
		{

			$type = ( isset ( $types[ $token ] ) ) ? $types[ $token ] : 'txt';

			if ( substr( $type , 0 , 4 ) === 'date' )
			{

				// Date is an array description with keys ( 'Y' : 5 , 'M' : 2 , ... )
				if ( is_array( $key ) && ( is_assoc( $key ) ) )
				{
					$newdate = array();
					foreach ( $key as $k => $v )
					{
						$newdate[ $k ] = @$out[ $v ][ 0 ];
					}

					if ( isset( $newdate[ 'U' ] ) )
					{
						$str = date( 'Y/m/d H:i:s' , $newdate[ 'U' ] );
					}
					else if ( isset( $newdate[ 'r' ] ) )
					{
						$str = date( 'Y/m/d H:i:s' , $newdate[ 'r' ] );
					}
					else if ( isset( $newdate[ 'c' ] ) )
					{
						$str = date( 'Y/m/d H:i:s' , $newdate[ 'c' ] );
					}
					else if ( isset( $newdate[ 'M' ] ) )
					{
						$str = trim( $newdate[ 'M' ] . ' ' . $newdate[ 'd' ] . ' ' . $newdate[ 'H' ] . ':' . $newdate[ 'i' ] . ':' . $newdate[ 's' ] . ' ' . $newdate[ 'Y' ] . ' ' . @$newdate[ 'z' ] );
					}
					elseif ( isset( $newdate[ 'm' ] ) )
					{
						$str = trim( $newdate[ 'Y' ] . '/' . $newdate[ 'm' ] . '/' . $newdate[ 'd' ] . ' ' . $newdate[ 'H' ] . ':' . $newdate[ 'i' ] . ':' . $newdate[ 's' ] . ' ' . @$newdate[ 'z' ] );
					}
				}

				// Date is an array description without keys ( 2 , ':' , 3 , '-' , ... )
				else if ( is_array( $key ) )
				{
					$str = '';
					foreach ( $key as $v )
					{
						$str .= ( is_string( $v ) ) ? $v : @$out[ $v ][ 0 ];
					}
				}

				else
				{
					$str = @$out[ $key ][ 0 ];
				}

				// remove part next to the last /
				$dateformat = ( substr( $type , 0 , 5 ) === 'date:' ) ? substr( $type , 5 ) : 'Y/m/d H:i:s';

				if ( ( $p = strrpos( $dateformat , '/' ) ) !== false )
				{
					$dateformat = substr( $dateformat , 0 , $p );
				}

				$matches = array();
				if ( ( preg_match( '/([A-Z]{3}) ([A-Z]{3}) ([0-9]{1,2}) ([0-9]{2}:[0-9]{2}:[0-9]{2})\.[0-9]+ ([0-9]{2,4})/i', $str, $matches ) ) )
				{
					// Remove microseconds from datetime string
					$str = $matches[1] . ' ' . $matches[2] . ' ' . $matches[3] . ' ' . $matches[4] . ' ' . $matches[5];
				}

				if ( ( $timestamp = strtotime( $str ) ) === false )
				{
					$formatted_date = "ERROR ! Unable to convert this string to date : <code>$str</code>";
					$timestamp      = 0;
				}

				else
				{

					if ( version_compare( PHP_VERSION , '5.3.0' ) >= 0 )
					{
						$date = new DateTime();
						$date->setTimestamp( $timestamp );
					}
					else
					{
						$date = new DateTime( "@" . $timestamp );
					}

					if ( ! is_null( $tz ) && $tz !== '' )
					{
						$date->setTimezone( new DateTimeZone( $tz ) );
					}

					$formatted_date = $date->format( $dateformat );
					$timestamp      = (int)$date->format( 'U' );
				}

				$result[ $token ] = $formatted_date;
			}
			// Array description without keys ( 2 , ':' , 3 , '-' , ... )
			else if ( is_array( $key ) )
			{
				$r = '';
				foreach ( $key as $v )
				{
					$r .= ( is_string( $v ) ) ? $v : @$out[ $v ][ 0 ];
				}
				$result[ $token ] = $r;
			}
			else
			{
				$result[ $token ] = @$out[ $key ][ 0 ];
			}
		}

		if ( $timestamp > 0 )
		{
			$result[ 'pmld' ] = $timestamp;
		}

		return $result;
	}


	/**
	 * Block-based parsing mode for multi-line log entries
	 *
	 * @param   string  $regex         A regex to match each block
	 * @param   array   $match         An array of matchers
	 * @param   array   $types         An array of matchers types
	 * @param   string  $tz            The wanted timezone to translate matchers with a date type
	 * @param   integer $wanted_lines  the count of wanted lines to be returned
	 * @param   array   $exclude       An array of exclusion matchers tokens
	 * @param   string  $file_path     the file path
	 * @param   integer $start_offset  the offset where to begin to parse file
	 * @param   integer $start_from    the position from where the offset is taken
	 * @param   boolean $load_more     loadmore mode : if true, we do not try to guess the previous line
	 * @param   string  $old_lastline  The fingerprint of the last known line (previous call)
	 * @param   string  $search        A search expression
	 * @param   integer $data_to_parse The maximum count of bytes to read
	 * @param   boolean $full          Whether the log file should be loaded from scratch
	 * @param   integer $max_search_log_time  The maximum duration in s to parse lines
	 * @param   string  $block_start   A regex pattern or string to identify the start of a log block
	 *
	 * @return  array                        Array of parsed logs with metadata
	 */
	private static function getNewLinesBlockMode( $regex , $match , $types , $tz , $wanted_lines , $exclude , $file_path , $start_offset , $start_from , $load_more , $old_lastline , $search , $data_to_parse , $full , $max_search_log_time , $block_start )
	{
		$fl = fopen( $file_path , "r" );
		if ( $fl === false )
		{
			return '1';
		}

		$logs            = array();
		$start           = microtime( true );
		$regsearch       = false;
		$found           = false;
		$bytes           = 0;
		$skip            = 0;
		$error           = 0;
		$abort           = false;
		$file_lastline   = '';
		$search_lastline = true;

		// Prepare block_start pattern - if it's not a regex, make it one
		$block_pattern = $block_start;
		if ( ! preg_match( '/^\/.*\/[imsxADSUXu]*$/' , $block_start ) )
		{
			// It's a plain string, escape it and make it a regex
			$block_pattern = '/^' . preg_quote( $block_start , '/' ) . '/';
		}

		// Get file metadata
		if ( version_compare( PHP_VERSION , '5.3.0' ) >= 0 )
		{
			$filem = new DateTime();
			$filem->setTimestamp( filemtime( $file_path ) );
		}
		else
		{
			$filem = new DateTime( "@" . filemtime( $file_path ) );
		}
		if ( ! is_null( $tz ) )
		{
			$filem->setTimezone( new DateTimeZone( $tz ) );
		}
		$filemu   = $filem->format( 'U' );
		$filem    = $filem->format( 'Y/m/d H:i:s' );
		$filesize = filesize( $file_path );

		// Try to guess if the search expression is a regexp or not
		if ( $search !== '' )
		{
			$test      = @preg_match( $search , 'this is just a test !' );
			$regsearch = ( $test === false ) ? false : true;
		}

		// Determine starting position
		// For block mode, we need to read from a position that allows us to capture complete blocks
		if ( $load_more && $start_from === SEEK_SET )
		{
			fseek( $fl , $start_offset );
		}
		else
		{
			// For block mode, we read from the beginning or from a position that ensures we get complete blocks
			if ( $full )
			{
				fseek( $fl , 0 , SEEK_SET );
			}
			else
			{
				// Read only new data from the end, but go back a bit to ensure we capture block start
				// Go back up to 64KB to find the previous block start
				$read_from = max( 0 , $filesize - max( $data_to_parse , 65536 ) );
				fseek( $fl , $read_from , SEEK_SET );
				
				// Read first line to check if we're in the middle of a block
				$first_line = fgets( $fl );
				if ( $first_line !== false && ! preg_match( $block_pattern , rtrim( $first_line , "\r\n" ) ) )
				{
					// We're in the middle of a block, skip until we find the next block start
					while ( ( $line = fgets( $fl ) ) !== false )
					{
						$bytes += strlen( $line );
						if ( preg_match( $block_pattern , rtrim( $line , "\r\n" ) ) )
						{
							// Found block start, rewind to start of this line
							fseek( $fl , -strlen( $line ) , SEEK_CUR );
							break;
						}
					}
				}
				else
				{
					// We're at a block start, rewind to beginning of this line
					if ( $first_line !== false )
					{
						fseek( $fl , -strlen( $first_line ) , SEEK_CUR );
					}
				}
			}
		}

		$current_block = '';
		$in_block      = false;
		$ln            = 0;
		$block_start_pos = 0;

		// Read file line by line
		while ( ( $line = fgets( $fl ) ) !== false )
		{
			$bytes += strlen( $line );
			$line = rtrim( $line , "\r\n" );

			// Check if this line starts a new block
			if ( preg_match( $block_pattern , $line ) )
			{
				// Process previous block if exists
				if ( $in_block && $current_block !== '' )
				{
					$log = self::parseLine( $regex , $match , $current_block , $types , $tz );

					if ( is_array( $log ) )
					{
						$return_log = true;

						// Check exclusions
						foreach ( $log as $key => $value )
						{
							if ( ( isset( $exclude[ $key ] ) ) && ( is_array( $exclude[ $key ] ) ) )
							{
								foreach ( $exclude[ $key ] as $ekey => $reg )
								{
									try
									{
										if ( preg_match( $reg , $value ) )
										{
											$return_log = false;
											break 2;
										}
									}
									catch ( Exception $e )
									{
									}
								}
							}
						}

						// Filter by search
						if ( $return_log && ! empty( $search ) )
						{
							if ( $regsearch )
							{
								$return_log = preg_match( $search , $current_block );
								if ( $return_log === 0 )
								{
									$return_log = false;
								}
							}
							else
							{
								$return_log = strpos( $current_block , $search ) !== false;
							}
						}

						if ( $return_log )
						{
							$found            = true;
							$log[ 'pml' ]     = $current_block;
							$log[ 'pmlo' ]    = $block_start_pos;
							$logs[ 'logs' ][] = $log;
							$ln++;

							// Get the last line for fingerprint
							if ( $search_lastline === true )
							{
								$lines = explode( "\n" , $current_block );
								$file_lastline = sha1( end( $lines ) );
								$search_lastline = false;
							}
						}
						else
						{
							$skip++;
						}
					}
					else
					{
						$error++;
					}

					// Break if we have found the wanted count of logs
					if ( $ln >= $wanted_lines )
					{
						break;
					}
				}

				// Start new block
				$current_block = $line;
				$in_block = true;
				$block_start_pos = ftell( $fl ) - strlen( $line ) - 1;
			}
			elseif ( $in_block )
			{
				// Continue current block
				$current_block .= "\n" . $line;
			}

			// Break if time computing is too high
			if ( microtime( true ) - $start > $max_search_log_time )
			{
				$abort = true;
				break;
			}
		}

		// Process last block if exists
		if ( $in_block && $current_block !== '' && $ln < $wanted_lines )
		{
			$log = self::parseLine( $regex , $match , $current_block , $types , $tz );

			if ( is_array( $log ) )
			{
				$return_log = true;

				// Check exclusions
				foreach ( $log as $key => $value )
				{
					if ( ( isset( $exclude[ $key ] ) ) && ( is_array( $exclude[ $key ] ) ) )
					{
						foreach ( $exclude[ $key ] as $ekey => $reg )
						{
							try
							{
								if ( preg_match( $reg , $value ) )
								{
									$return_log = false;
									break 2;
								}
							}
							catch ( Exception $e )
							{
							}
						}
					}
				}

				// Filter by search
				if ( $return_log && ! empty( $search ) )
				{
					if ( $regsearch )
					{
						$return_log = preg_match( $search , $current_block );
						if ( $return_log === 0 )
						{
							$return_log = false;
						}
					}
					else
					{
						$return_log = strpos( $current_block , $search ) !== false;
					}
				}

				if ( $return_log )
				{
					$found            = true;
					$log[ 'pml' ]     = $current_block;
					$log[ 'pmlo' ]    = $block_start_pos;
					$logs[ 'logs' ][] = $log;
					$ln++;

					// Get the last line for fingerprint
					if ( $search_lastline === true )
					{
						$lines = explode( "\n" , $current_block );
						$file_lastline = sha1( end( $lines ) );
						$search_lastline = false;
					}
				}
				else
				{
					$skip++;
				}
			}
			else
			{
				$error++;
			}
		}

		$last_parsed_offset = ftell( $fl );
		fclose( $fl );

		// Reverse logs array to show newest first (since we read from beginning)
		if ( ! $load_more && ! $full )
		{
			$logs[ 'logs' ] = array_reverse( $logs[ 'logs' ] );
		}

		$logs[ 'found' ]       = $found;
		$logs[ 'abort' ]       = $abort;
		$logs[ 'regsearch' ]   = $regsearch;
		$logs[ 'search' ]      = $search;
		$logs[ 'full' ]        = $full;
		$logs[ 'lpo' ]         = $last_parsed_offset;
		$logs[ 'count' ]        = $ln;
		$logs[ 'bytes' ]        = $bytes;
		$logs[ 'skiplines' ]   = $skip;
		$logs[ 'errorlines' ]  = $error;
		$logs[ 'fingerprint' ] = md5( serialize( @$logs[ 'logs' ] ) );
		$logs[ 'lastline' ]    = $file_lastline;
		$logs[ 'duration' ]    = (int)( ( microtime( true ) - $start ) * 1000 );
		$logs[ 'filesize' ]    = $filesize;
		$logs[ 'filemodif' ]   = $filem;
		$logs[ 'filemodifu' ]  = $filemu;

		return $logs;
	}
}
