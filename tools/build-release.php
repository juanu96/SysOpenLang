<?php
/**
 * Build a clean WordPress-installable SysOpenLang archive.
 *
 * Usage: php tools/build-release.php
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root       = dirname( __DIR__ );
$plugin     = file_get_contents( $root . DIRECTORY_SEPARATOR . 'sysopenlang.php' );
$version    = preg_match( '/^\s*\*\s*Version:\s*([^\s]+)/mi', $plugin, $matches ) ? $matches[1] : '';
if ( '' === $version ) {
	fwrite( STDERR, "Could not determine the plugin version.\n" );
	exit( 1 );
}
$archive    = dirname( $root ) . DIRECTORY_SEPARATOR . 'sysopenlang.zip';
$directories = array( 'assets', 'docs', 'languages', 'src' );
$files       = array( 'CHANGELOG.md', 'LICENSE', 'SECURITY.md', 'sysopenlang.php', 'readme.txt', 'uninstall.php' );
$entries     = array();

foreach ( $directories as $directory ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . DIRECTORY_SEPARATOR . $directory, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $item ) {
		if ( ! $item->isFile() ) {
			continue;
		}
		$relative = substr( $item->getPathname(), strlen( $root ) + 1 );
		$entries[ 'sysopenlang/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative ) ] = $item->getPathname();
	}
}

foreach ( $files as $file ) {
	$entries[ 'sysopenlang/' . $file ] = $root . DIRECTORY_SEPARATOR . $file;
}

ksort( $entries );

if ( class_exists( 'ZipArchive' ) ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		fwrite( STDERR, "Could not create the release archive.\n" );
		exit( 1 );
	}
	foreach ( $entries as $name => $path ) {
		$zip->addFile( $path, $name );
	}
	$zip->close();
} else {
	// Create a standards-compliant stored ZIP when the optional PHP Zip extension is unavailable.
	$body = '';
	$central = '';
	$count = 0;
	foreach ( $entries as $name => $path ) {
		$data = file_get_contents( $path );
		if ( false === $data ) {
			fwrite( STDERR, "Could not read {$path}.\n" );
			exit( 1 );
		}
		$offset = strlen( $body );
		$size = strlen( $data );
		$crc = crc32( $data );
		$name_length = strlen( $name );
		$body .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $name_length, 0 ) . $name . $data;
		$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $name_length, 0, 0, 0, 0, 0, $offset ) . $name;
		$count++;
	}
	$output = $body . $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen( $central ), strlen( $body ), 0 );
	if ( false === file_put_contents( $archive, $output ) ) {
		fwrite( STDERR, "Could not write the release archive.\n" );
		exit( 1 );
	}
}

fwrite( STDOUT, $archive . PHP_EOL );
