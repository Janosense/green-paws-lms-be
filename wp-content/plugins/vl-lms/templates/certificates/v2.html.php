<?php
/**
 * Certificate Template v2
 *
 * Layout: A4 portrait (210mm × 297mm) over a full-page raster background
 * (`assets/v2-bg.png`, 300 DPI export of the branded design: Green Paws
 * badge on top, pale panel with ghost «СЕРТИФІКАТ» heading and paw
 * prints). The background is inlined as a data URI at render time so
 * dompdf never touches the filesystem (its chroot points at the uploads
 * dir, not the plugin dir).
 *
 * Background geometry (measured off the artwork, in mm):
 *   - pale panel: y 64–278, x 16–194
 *   - ghost «СЕРТИФІКАТ»: y 79–96
 *   - paw prints: y 189–250
 * Dynamic content therefore sits in the clean band y 96–188, the footer
 * row in the clear strip y 253–276, and the verification UUID on the
 * white margin below the panel.
 *
 * Fonts: Onest (registered by PdfGenerator), DejaVu Sans fallback —
 * Cyrillic coverage is preserved either way.
 * Colors:
 *   - brand-600 #397E49 (accents)
 *   - stone-900 #26221C (body text)
 *   - stone-500 #897E6E (subdued)
 *
 * dompdf CSS subset — no flexbox, no grid, no CSS variables. Layout is
 * absolute-positioned in millimetres, with `text-align: center` for
 * horizontal centring.
 *
 * Available variables (extracted before include):
 *   string       $course_title
 *   string       $learner_full_name
 *   list<string> $instructor_names
 *   string       $issuer_name
 *   string       $issued_at_formatted   e.g. "29 квітня 2026"
 *   ?int         $final_score_pct
 *   string       $verification_uuid
 *   string       $verification_url
 *   string       $qr_data_uri           data:image/png;base64,...
 *   string       $template_version      "v2"
 *
 * @author Tymofii Synianskyi
 */
defined( 'ABSPATH' ) || exit;

$instructor_label = count( $instructor_names ) === 1 ? 'Інструктор:' : 'Інструктори:';
$instructor_line  = implode( ', ', $instructor_names );

$bg_file     = __DIR__ . '/assets/v2-bg.png';
$bg_contents = is_file( $bg_file ) ? file_get_contents( $bg_file ) : false;
$bg_data_uri = false === $bg_contents ? '' : 'data:image/png;base64,' . base64_encode( $bg_contents );
?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<style>
@page { margin: 0; }
html, body {
    margin: 0;
    padding: 0;
    width: 210mm;
    height: 297mm;
    font-family: Onest, 'DejaVu Sans', sans-serif;
    color: #26221C;
}
.page {
    position: relative;
    width: 210mm;
    height: 297mm;
    padding: 0;
}
.bg {
    position: absolute;
    top: 0;
    left: 0;
    width: 210mm;
    height: 297mm;
}
.subhead {
    position: absolute;
    top: 106mm;
    left: 0;
    width: 210mm;
    text-align: center;
    font-size: 10pt;
    color: #897E6E;
    text-transform: uppercase;
    letter-spacing: 3pt;
}
.learner {
    position: absolute;
    top: 114mm;
    left: 16mm;
    width: 178mm;
    text-align: center;
    font-size: 26pt;
    color: #397E49;
    font-weight: bold;
}
.subhead-2 {
    position: absolute;
    top: 132mm;
    left: 0;
    width: 210mm;
    text-align: center;
    font-size: 10pt;
    color: #897E6E;
}
.course {
    position: absolute;
    top: 140mm;
    left: 36mm;
    width: 138mm;
    text-align: center;
    font-size: 15pt;
    color: #26221C;
    font-weight: bold;
}
.score {
    position: absolute;
    top: 160mm;
    left: 0;
    width: 210mm;
    text-align: center;
    font-size: 10.5pt;
    color: #26221C;
}
.instructors {
    position: absolute;
    top: 254mm;
    left: 26mm;
    width: 62mm;
    font-size: 9pt;
    line-height: 1.3;
}
.instructors .label {
    color: #897E6E;
    text-transform: uppercase;
    letter-spacing: 1.5pt;
    font-size: 7.5pt;
}
.instructors .name {
    color: #26221C;
    font-weight: bold;
}
.issuer {
    position: absolute;
    top: 254mm;
    left: 74mm;
    width: 62mm;
    text-align: center;
    font-size: 9pt;
    line-height: 1.3;
}
.issuer .label {
    color: #897E6E;
    text-transform: uppercase;
    letter-spacing: 1.5pt;
    font-size: 7.5pt;
}
.issuer .name {
    color: #26221C;
    font-weight: bold;
}
.qr {
    position: absolute;
    top: 252mm;
    left: 160mm;
    width: 28mm;
    text-align: right;
}
.qr img {
    width: 20mm;
    height: 20mm;
}
.uuid {
    position: absolute;
    top: 281mm;
    left: 0;
    width: 210mm;
    text-align: center;
    font-family: DejaVu Sans Mono, monospace;
    font-size: 6pt;
    color: #897E6E;
}
</style>
</head>
<body>
<div class="page">
    <?php if ( '' !== $bg_data_uri ) : ?>
    <img class="bg" src="<?php echo htmlspecialchars( $bg_data_uri, ENT_QUOTES, 'UTF-8' ); ?>" alt="">
    <?php endif; ?>

    <div class="subhead">видається</div>

    <div class="learner"><?php echo htmlspecialchars( $learner_full_name, ENT_QUOTES, 'UTF-8' ); ?></div>

    <div class="subhead-2">за успішне проходження курсу</div>

    <div class="course"><?php echo htmlspecialchars( $course_title, ENT_QUOTES, 'UTF-8' ); ?></div>

    <?php if ( null !== $final_score_pct ) : ?>
    <div class="score">
        Підсумкова оцінка:
        <strong><?php echo (int) $final_score_pct; ?>%</strong>
    </div>
    <?php endif; ?>

    <?php if ( '' !== $instructor_line ) : ?>
    <div class="instructors">
        <div class="label"><?php echo htmlspecialchars( $instructor_label, ENT_QUOTES, 'UTF-8' ); ?></div>
        <div class="name"><?php echo htmlspecialchars( $instructor_line, ENT_QUOTES, 'UTF-8' ); ?></div>
    </div>
    <?php endif; ?>

    <div class="issuer">
        <div class="label">Видано</div>
        <div class="name"><?php echo htmlspecialchars( $issuer_name, ENT_QUOTES, 'UTF-8' ); ?></div>
        <div><?php echo htmlspecialchars( $issued_at_formatted, ENT_QUOTES, 'UTF-8' ); ?></div>
    </div>

    <?php if ( '' !== $qr_data_uri ) : ?>
    <div class="qr">
        <img src="<?php echo htmlspecialchars( $qr_data_uri, ENT_QUOTES, 'UTF-8' ); ?>" alt="">
    </div>
    <?php endif; ?>

    <div class="uuid"><?php echo htmlspecialchars( $verification_uuid, ENT_QUOTES, 'UTF-8' ); ?></div>
</div>
</body>
</html>
