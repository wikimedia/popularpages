<?php

/**
 * Log given message to file
 *
 * @param string $message Message to record in file
 * @param string $wiki
 */
function wfLogToFile( string $message, string $wiki ): void {
	$file = fopen( __DIR__ . "/../logs/log-$wiki.txt", 'a' );
	$output = date( 'Y-m-d H:i:s' ) . '  ' . $message;
	fwrite( $file, $output . PHP_EOL );
	fclose( $file );
}
