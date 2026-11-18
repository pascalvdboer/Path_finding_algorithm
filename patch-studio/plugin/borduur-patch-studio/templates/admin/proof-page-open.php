<?php
/**
 * Opening of the customer proof pages (single line and whole order): document head (standalone) or theme header,
 * styles and the page wrapper. Closed by proof-page-close.php.
 *
 * Standalone by default (no theme or third-party scripts next to a URL with a token, security-5). With the filter
 * bps_proof_page_use_theme the theme header is used; the wrapper then carries lang/dir and is a region, so the
 * page keeps a single <main> (i18n-a11y-14).
 *
 * @package BorduurPatchStudio
 * @var string $bps_title  Page title.
 * @var string $lang       nl|fr
 * @var bool   $use_theme  Wrap in the theme header/footer.
 */

defined( 'ABSPATH' ) || exit;

$bps_html_lang = 'fr' === $lang ? 'fr-BE' : 'nl-BE';

if ( $use_theme ) {
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
	add_action(
		'wp_head',
		static function () {
			echo '<meta name="referrer" content="no-referrer">' . "\n";
		},
		0
	);
	add_filter(
		'pre_get_document_title',
		static function () use ( $bps_title ) {
			return $bps_title;
		}
	);
	get_header();
} else {
	?><!doctype html>
<html lang="<?php echo esc_attr( $bps_html_lang ); ?>" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html( $bps_title . ' · ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></title>
</head>
<body class="bps-proofbody">
	<?php
}
?>
<style>
	body.bps-proofbody { margin: 0; background: #F5F8FB; }
	.bps-proofpage { --bps-cta:#0B74A6; --bps-navy:#0A1F44; --bps-blue:#21A9E2; --bps-ink:#1E1B1B; --bps-muted:#5C5C5C; --bps-line:#E3EBF2;
		box-sizing: border-box; max-width: 760px; margin: 24px auto 32px; padding: 0 16px; color: var(--bps-ink); font-family: "Assistant", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 17px; line-height: 1.55; }
	.bps-proofpage *, .bps-proofpage *::before, .bps-proofpage *::after { box-sizing: inherit; }
	.bps-proofpage h1 { font-family: "Sora", system-ui, sans-serif; font-weight: 700; color: var(--bps-navy); font-size: clamp(22px, 4vw, 32px); line-height: 1.2; margin: 0 0 6px; text-transform: none; letter-spacing: 0; }
	.bps-proofpage h2 { font-family: "Sora", system-ui, sans-serif; font-size: 19px; color: var(--bps-navy); margin: 0 0 10px; }
	.bps-proofpage .bps-pp__sub { color: var(--bps-muted); margin: 0 0 16px; }
	.bps-proofpage .bps-pp__card { background: #fff; border: 1px solid var(--bps-line); border-radius: 12px; padding: 20px; box-shadow: 0 4px 24px rgba(10,31,68,.06); margin: 0 0 16px; }
	.bps-proofpage .bps-pp__figure { margin: 0 0 16px; text-align: center; }
	.bps-proofpage .bps-pp__imglink { display: inline-block; border-radius: 8px; }
	.bps-proofpage .bps-pp__img { display: block; margin: 0 auto; max-width: min(100%, 420px); max-height: 46vh; width: auto; height: auto; border-radius: 8px; background: #F5F8FB; object-fit: contain; }
	.bps-proofpage .bps-pp__zoom { display: inline-block; margin-top: 6px; font-size: 15px; }
	.bps-proofpage table { width: 100%; border-collapse: collapse; margin: 0 0 16px; font-size: 15px; }
	.bps-proofpage th, .bps-proofpage td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--bps-line); vertical-align: top; }
	.bps-proofpage th { width: 34%; font-weight: 600; color: var(--bps-navy); }
	.bps-proofpage .bps-pp__sw { display: inline-block; width: 14px; height: 14px; border-radius: 3px; border: 1px solid rgba(0,0,0,.35); vertical-align: -2px; margin-right: 6px; }
	.bps-proofpage label { font-weight: 700; color: var(--bps-navy); }
	.bps-proofpage .bps-pp__label { display: block; margin: 0 0 6px; }
	.bps-proofpage textarea { width: 100%; min-height: 96px; padding: 12px; font: inherit; border: 1px solid #767676; border-radius: 8px; background: #fff; color: var(--bps-ink); }
	.bps-proofpage .bps-pp__help { color: var(--bps-muted); font-size: 15px; margin: 6px 0 12px; }
	.bps-proofpage .bps-pp__btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 12px 24px; border: 0; border-radius: 8px; background: var(--bps-cta); color: #fff; font: 700 17px/1.2 "Sora", system-ui, sans-serif; text-decoration: none; cursor: pointer; width: 100%; }
	.bps-proofpage .bps-pp__btn:hover { background: var(--bps-navy); color: #fff; }
	.bps-proofpage .bps-pp__btn--ghost { background: #fff; color: var(--bps-cta); border: 2px solid var(--bps-cta); }
	.bps-proofpage .bps-pp__btn--ghost:hover { background: #EEF6FB; color: var(--bps-navy); }
	.bps-proofpage .bps-pp__btn--danger { background: #B42318; }
	.bps-proofpage a:focus-visible, .bps-proofpage button:focus-visible, .bps-proofpage textarea:focus-visible, .bps-proofpage input:focus-visible { outline: 3px solid #5926C5; outline-offset: 2px; }
	.bps-proofpage .bps-pp__check { display: flex; gap: 12px; align-items: flex-start; font-weight: 600; margin: 0 0 12px; cursor: pointer; min-height: 48px; }
	.bps-proofpage .bps-pp__check input { width: 24px; height: 24px; margin: 2px 0 0; flex: none; accent-color: var(--bps-cta); }
	.bps-proofpage .bps-pp__bar { background: #fff; border-top: 1px solid var(--bps-line); padding: 12px 0 4px; }
	.bps-proofpage .bps-pp__alt { text-align: center; margin: 12px 0 0; }
	.bps-proofpage .bps-pp__alt a, .bps-proofpage .bps-pp__contact a, .bps-proofpage .bps-pp__zoom { color: var(--bps-cta); }
	.bps-proofpage .bps-pp__alt a { display: inline-flex; align-items: center; min-height: 44px; padding: 0 8px; }
	.bps-proofpage .bps-pp__error { border: 1px solid #B42318; background: #FEF3F2; color: #B42318; border-radius: 8px; padding: 10px 14px; margin: 0 0 12px; }
	.bps-proofpage .bps-pp__ok { border-left: 4px solid #15803D; background: #F0FAF3; padding: 14px 16px; border-radius: 8px; margin: 0 0 16px; }
	.bps-proofpage .bps-pp__info { border-left: 4px solid var(--bps-blue); background: #F7FBFE; padding: 14px 16px; border-radius: 8px; margin: 0 0 16px; }
	.bps-proofpage .bps-pp__warn { border-left: 4px solid #B42318; background: #FEF3F2; padding: 14px 16px; border-radius: 8px; margin: 0 0 16px; }
	.bps-proofpage blockquote { margin: 0 0 16px; padding: 12px 16px; border-left: 4px solid var(--bps-line); background: #FAFBFC; white-space: pre-line; }
	.bps-proofpage .bps-pp__contact { color: var(--bps-muted); font-size: 15px; margin: 16px 0 0; text-align: center; }
	.bps-proofpage .bps-pp__choices { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 8px; border: 0; padding: 0; }
	.bps-proofpage .bps-pp__choices legend { font-weight: 700; color: var(--bps-navy); margin: 0 0 6px; padding: 0; }
	.bps-proofpage .bps-pp__choice { display: flex; align-items: center; gap: 8px; min-height: 48px; padding: 8px 14px; border: 1px solid #767676; border-radius: 8px; font-weight: 600; cursor: pointer; }
	.bps-proofpage .bps-pp__choice input { width: 20px; height: 20px; margin: 0; }
	.bps-proofpage .bps-pp__line { font-weight: 700; color: var(--bps-navy); }
	@media (max-width: 640px) {
		.bps-proofpage { margin-top: 12px; font-size: 16px; }
		.bps-proofpage .bps-pp__card { padding: 14px; }
		.bps-proofpage .bps-pp__img { max-height: 34vh; }
		/* Sticky action bar: the confirm checkbox and the main button stay in view without scrolling. */
		.bps-proofpage .bps-pp__bar { position: sticky; bottom: 0; z-index: 5; margin: 0 -14px -14px; padding: 10px 14px calc(10px + env(safe-area-inset-bottom)); box-shadow: 0 -6px 18px rgba(10,31,68,.10); border-radius: 0 0 12px 12px; }
		.bps-proofpage .bps-pp__bar .bps-pp__check { margin-bottom: 8px; font-size: 15px; }
	}
</style>
<?php if ( $use_theme ) : ?>
<div class="bps-proofpage" id="bps-proof" role="region" aria-labelledby="bps-proof-title" lang="<?php echo esc_attr( $bps_html_lang ); ?>" dir="ltr">
<?php else : ?>
<main class="bps-proofpage" id="bps-proof">
<?php endif; ?>
	<h1 id="bps-proof-title"><?php echo esc_html( $bps_title ); ?></h1>
