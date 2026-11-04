<?php
/**
 * Standalone logic tests for TBTF_Phrase.
 *
 * tokenize() is the contract between the teacher page, the database and the
 * game: if it splits a phrase wrongly, a student cannot win. These are the
 * rules from the spec (§5) a teacher would otherwise have to catch by eye.
 *
 * WordPress is not loaded; TBTF_Phrase does not use it.
 *
 * Run: php tests/test-phrase.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-tbtf-phrase.php';

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: {$label}\n";
	}
}
function same( $actual, $expected, $label ) {
	ok( $actual === $expected, $label . ( $actual === $expected ? '' : "\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) ) );
}

/* ---------- tokenize: the four spec examples ---------- */

same( TBTF_Phrase::tokenize( "How's it going?" ), array( "how's", 'it', 'going' ), 'spec: How\'s it going?' );
same( TBTF_Phrase::tokenize( "Sorry, I didn't catch that." ), array( 'sorry', 'I', "didn't", 'catch', 'that' ), 'spec: Sorry, I didn\'t catch that.' );
same( TBTF_Phrase::tokenize( 'What do you mean by…?' ), array( 'what', 'do', 'you', 'mean', 'by' ), 'spec: What do you mean by…?' );
same( TBTF_Phrase::tokenize( 'Likewise' ), array( 'likewise' ), 'spec: Likewise (one tile)' );

/* ---------- tokenize: the five greeting fixtures ---------- */

same( TBTF_Phrase::tokenize( 'How are things?' ), array( 'how', 'are', 'things' ), 'fixture: How are things?' );
same( TBTF_Phrase::tokenize( 'Nice to see you!' ), array( 'nice', 'to', 'see', 'you' ), 'fixture: Nice to see you!' );
same( TBTF_Phrase::tokenize( 'Long time no see!' ), array( 'long', 'time', 'no', 'see' ), 'fixture: Long time no see!' );
same( TBTF_Phrase::tokenize( 'How have you been?' ), array( 'how', 'have', 'you', 'been' ), 'fixture: How have you been?' );

/* ---------- the I rule ---------- */

same( TBTF_Phrase::tokenize( "I'm fine, thanks." ), array( "I'm", 'fine', 'thanks' ), "I'm stays capitalised" );
same( TBTF_Phrase::tokenize( "I've been better, I'd say!" ), array( "I've", 'been', 'better', "I'd", 'say' ), "I've / I'd stay capitalised" );
same( TBTF_Phrase::tokenize( "I'll see." ), array( "I'll", 'see' ), "I'll stays capitalised" );
same( TBTF_Phrase::tokenize( 'Can I help?' ), array( 'can', 'I', 'help' ), 'lone I stays capitalised mid-phrase' );
same( TBTF_Phrase::tokenize( 'i think so' ), array( 'I', 'think', 'so' ), 'lowercase i becomes I' );
same( TBTF_Phrase::tokenize( "i'm in" ), array( "I'm", 'in' ), "lowercase i'm becomes I'm" );
same( TBTF_Phrase::tokenize( 'It is Ice cold' ), array( 'it', 'is', 'ice', 'cold' ), 'words starting with I are lowercased' );
same( TBTF_Phrase::tokenize( 'In Italy' ), array( 'in', 'italy' ), 'In / Italy are lowercased' );

/* ---------- punctuation and ellipsis ---------- */

same( TBTF_Phrase::tokenize( 'Well... you know' ), array( 'well', 'you', 'know' ), 'mid-phrase ... removed' );
same( TBTF_Phrase::tokenize( 'Well…you know' ), array( 'well', 'you', 'know' ), 'ellipsis between words splits them' );
same( TBTF_Phrase::tokenize( 'Wait for it...' ), array( 'wait', 'for', 'it' ), 'trailing ... removed' );
same( TBTF_Phrase::tokenize( '  Really?!  ' ), array( 'really' ), 'trim + several trailing marks' );
same( TBTF_Phrase::tokenize( 'Note: this; that, other' ), array( 'note', 'this', 'that', 'other' ), 'colons, semicolons, commas stripped' );
same( TBTF_Phrase::tokenize( 'He said "hello" to me' ), array( 'he', 'said', 'hello', 'to', 'me' ), 'surrounding quotes stripped' );
same( TBTF_Phrase::tokenize( '“Cheers,” he said' ), array( 'cheers', 'he', 'said' ), 'curly quotes stripped' );
same( TBTF_Phrase::tokenize( 'A well-known face' ), array( 'a', 'well-known', 'face' ), 'hyphenated word stays whole' );
same( TBTF_Phrase::tokenize( "How’s it going?" ), array( "how's", 'it', 'going' ), 'curly apostrophe normalised' );
same( TBTF_Phrase::tokenize( "the dogs' bowl" ), array( 'the', 'dogs', 'bowl' ), 'trailing apostrophe treated as a quote' );
same( TBTF_Phrase::tokenize( 'Hi! How are you?' ), array( 'hi', 'how', 'are', 'you' ), 'mid-phrase ! stripped from its word' );
same( TBTF_Phrase::tokenize( '' ), array(), 'empty phrase → no tiles' );
same( TBTF_Phrase::tokenize( '  ?! , ' ), array(), 'punctuation only → no tiles' );
same( TBTF_Phrase::tokenize( "Over\tand\n out" ), array( 'over', 'and', 'out' ), 'any whitespace splits' );
same( TBTF_Phrase::tokenize( 'Bye , bye' ), array( 'bye', 'bye' ), 'a lone comma is dropped as an empty token' );
same( TBTF_Phrase::tokenize( 'Bye bye!' ), array( 'bye', 'bye' ), 'duplicate words both kept' );

/* ---------- tile checks ---------- */

same( TBTF_Phrase::tile_check( array() ), 'empty', 'tile_check: empty' );
same( TBTF_Phrase::tile_check( array( 'a' ) ), 'ok', 'tile_check: one tile is fine' );
same( TBTF_Phrase::tile_check( array_fill( 0, 6, 'a' ) ), 'ok', 'tile_check: six is fine' );
same( TBTF_Phrase::tile_check( array_fill( 0, 7, 'a' ) ), 'warn', 'tile_check: seven warns' );
same( TBTF_Phrase::tile_check( array_fill( 0, 8, 'a' ) ), 'warn', 'tile_check: eight warns' );
same( TBTF_Phrase::tile_check( array_fill( 0, 9, 'a' ) ), 'long', 'tile_check: nine is too long' );

/* ---------- parse_distractors ---------- */

same( TBTF_Phrase::parse_distractors( 'doing, you' ), array( 'doing', 'you' ), 'parse: comma list' );
same( TBTF_Phrase::parse_distractors( ' Doing ,doing, DOING ' ), array( 'doing' ), 'parse: trimmed, lowercased, deduplicated' );
same( TBTF_Phrase::parse_distractors( 'a, b, c, d' ), array( 'a', 'b', 'c' ), 'parse: max three' );
same( TBTF_Phrase::parse_distractors( ', ,' ), array(), 'parse: empties dropped' );
same( TBTF_Phrase::parse_distractors( "i'm, I" ), array( "I'm", 'I' ), 'parse: I rule applies' );
same( TBTF_Phrase::parse_distractors( array( 'seen', 'not!' ) ), array( 'seen', 'not' ), 'parse: list input, punctuation stripped' );

/* ---------- distractors ---------- */

$set = array(
	'distractor_mode' => 'hand',
	'items'           => array(
		array( 'english' => "How's it going?", 'distractors' => 'doing, you' ),
		array( 'english' => 'How are things?', 'distractors' => 'is, thing' ),
		array( 'english' => 'Nice to see you!', 'distractors' => 'meet, your' ),
		array( 'english' => 'Long time no see!', 'distractors' => '' ),
		array( 'english' => 'How have you been?', 'distractors' => 'are, being, how' ),
	),
);
$items = $set['items'];

same( TBTF_Phrase::distractors( $items[0], $set, 2 ), array( 'doing', 'you' ), 'hand: own words, in order' );
same( TBTF_Phrase::distractors( $items[0], $set, 1 ), array( 'doing' ), 'hand: first $count' );
same( TBTF_Phrase::distractors( $items[0], $set, 0 ), array(), 'count 0 → none' );
same( TBTF_Phrase::distractors( $items[4], $set, 3 ), array( 'are', 'being' ), 'hand: an answer word ("how") is never a distractor' );

// Hand mode, item without its own: falls back to branch.
for ( $round = 0; $round < 25; $round++ ) {
	$d = TBTF_Phrase::distractors( $items[3], $set, 3 );
	ok( 3 === count( $d ), 'hand fallback: three branch words' );
	foreach ( $d as $w ) {
		ok( ! in_array( $w, array( 'long', 'time', 'no', 'see' ), true ), "hand fallback: '{$w}' is not an answer tile" );
	}
	ok( count( array_unique( $d ) ) === count( $d ), 'hand fallback: no repeats' );
}

// Branch mode excludes every answer tile, case-insensitively.
$branch = array( 'distractor_mode' => 'branch' ) + $set;
$pool   = array( "how's", 'it', 'going', 'how', 'are', 'things', 'nice', 'to', 'see', 'long', 'time', 'no', 'have', 'been' );
for ( $round = 0; $round < 50; $round++ ) {
	$d = TBTF_Phrase::distractors( $items[2], $branch, 2 );
	same( count( $d ), 2, 'branch: two words' );
	foreach ( $d as $w ) {
		ok( ! in_array( $w, array( 'nice', 'to', 'see', 'you' ), true ), "branch: '{$w}' is not an answer tile" );
		ok( in_array( $w, $pool, true ), "branch: '{$w}' comes from another item" );
	}
}
// Hand-picked words are ignored in branch mode.
$seen = array();
for ( $round = 0; $round < 50; $round++ ) {
	foreach ( TBTF_Phrase::distractors( $items[0], $branch, 3 ) as $w ) {
		$seen[ $w ] = true;
	}
}
ok( ! isset( $seen['doing'] ), 'branch: hand-picked words not used' );
ok( ! isset( $seen['it'] ) && ! isset( $seen["how's"] ) && ! isset( $seen['going'] ), 'branch: own tiles never used' );
ok( count( $seen ) > 3, 'branch: shuffled (several different words over 50 rounds)' );

// Case-insensitive exclusion: "I" in the answer removes "i"/"I" from the pool.
$iset = array(
	'distractor_mode' => 'branch',
	'items'           => array(
		array( 'english' => 'I see', 'distractors' => '' ),
		array( 'english' => 'Can I go', 'distractors' => '' ),
	),
);
for ( $round = 0; $round < 20; $round++ ) {
	$d = TBTF_Phrase::distractors( $iset['items'][0], $iset, 3 );
	ok( ! in_array( 'I', $d, true ), 'branch: I excluded when the answer has I' );
	same( count( $d ), 2, 'branch: only the two non-answer words remain' );
}

// A hand distractor equal to an answer tile (any case) is dropped.
$dup = array( 'english' => 'Long time no see!', 'distractors' => 'TIME, seen' );
same( TBTF_Phrase::distractors( $dup, $set, 3 ), array( 'seen' ), 'hand: distractor equal to an answer tile dropped' );

// One-item set in branch mode: nothing to draw from.
$solo = array( 'distractor_mode' => 'branch', 'items' => array( array( 'english' => 'Likewise' ) ) );
same( TBTF_Phrase::distractors( $solo['items'][0], $solo, 2 ), array(), 'branch: lone item has no distractors' );

/* ---------- slug ---------- */

$slug = TBTF_Phrase::generate_slug();
ok( 1 === preg_match( '/^[a-hjkmnp-z2-9]{12}$/', $slug ), 'slug: 12 chars, no look-alikes' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
