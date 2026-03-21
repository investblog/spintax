<?php
/**
 * Quick preview script — renders the fixture template and writes to /tmp.
 *
 * Usage: php render-preview.php
 */

// In wp-env, ./plugin is mapped to the plugin root (no nested plugin/ dir).
$candidates = array(
	dirname( __DIR__ ) . '/src/Core/Engine/Parser.php',
	dirname( __DIR__ ) . '/plugin/src/Core/Engine/Parser.php',
);
foreach ( $candidates as $path ) {
	if ( file_exists( $path ) ) {
		require_once $path;
		break;
	}
}

$parser   = new Spintax\Core\Engine\Parser();
$template = file_get_contents( __DIR__ . '/fixtures/review-casino.txt' );
$result   = $parser->process( $template );

$out = '/tmp/rendered-output.txt';
file_put_contents( $out, $result );
echo "Rendered " . strlen( $result ) . " bytes to {$out}\n";
