<?php
/** Run every standalone SysOpenLang regression test. */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$tests = glob( dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . '*.php' );
sort( $tests );
$failed = array();

foreach ( $tests as $test ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $test ), $status );
	if ( 0 !== $status ) {
		$failed[] = basename( $test );
	}
}

if ( $failed ) {
	fwrite( STDERR, 'Failed tests: ' . implode( ', ', $failed ) . PHP_EOL );
	exit( 1 );
}

fwrite( STDOUT, sprintf( "SysOpenLang: %d test files passed.\n", count( $tests ) ) );
