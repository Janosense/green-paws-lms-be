<?php
/**
 * Certificate Template v1
 *
 * Layout: A4 landscape (297mm × 210mm).
 * Fonts: DejaVu Sans (Cyrillic-capable, bundled with dompdf). Onest is
 *        not embedded — registering custom fonts in dompdf requires the
 *        `load_font.php` build step and is deferred per phase decision.
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
 *   string       $template_version      "v1"
 *
 * @author Tymofii Synianskyi
 */
defined( 'ABSPATH' ) || exit;

$instructor_label = count( $instructor_names ) === 1 ? 'Інструктор:' : 'Інструктори:';
$instructor_line  = implode( ', ', $instructor_names );
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
    width: 297mm;
    height: 210mm;
    font-family: DejaVu Sans, sans-serif;
    color: #26221C;
}
.page {
    position: relative;
    width: 297mm;
    height: 210mm;
    padding: 0;
}
.frame {
    position: absolute;
    top: 8mm;
    left: 8mm;
    right: 8mm;
    bottom: 8mm;
    border: 1mm solid #397E49;
}
.frame-inner {
    position: absolute;
    top: 12mm;
    left: 12mm;
    right: 12mm;
    bottom: 12mm;
    border: 0.3mm solid #397E49;
}
.divider {
    position: absolute;
    left: 50%;
    width: 80mm;
    margin-left: -40mm;
    height: 0.4mm;
    background-color: #397E49;
}
.divider.top    { top: 38mm; }
.divider.bottom { top: 56mm; }
.title {
    position: absolute;
    top: 41mm;
    left: 0;
    width: 297mm;
    text-align: center;
    font-family: DejaVu Serif, serif;
    font-size: 28pt;
    letter-spacing: 8pt;
    color: #397E49;
    font-weight: bold;
}
.subhead {
    position: absolute;
    top: 70mm;
    left: 0;
    width: 297mm;
    text-align: center;
    font-size: 11pt;
    color: #897E6E;
    text-transform: uppercase;
    letter-spacing: 3pt;
}
.learner {
    position: absolute;
    top: 80mm;
    left: 0;
    width: 297mm;
    text-align: center;
    font-family: DejaVu Serif, serif;
    font-size: 32pt;
    color: #397E49;
    font-weight: bold;
}
.subhead-2 {
    position: absolute;
    top: 105mm;
    left: 0;
    width: 297mm;
    text-align: center;
    font-size: 11pt;
    color: #897E6E;
}
.course {
    position: absolute;
    top: 115mm;
    left: 50mm;
    width: 197mm;
    text-align: center;
    font-family: DejaVu Serif, serif;
    font-style: italic;
    font-size: 18pt;
    color: #26221C;
}
.score {
    position: absolute;
    top: 138mm;
    left: 0;
    width: 297mm;
    text-align: center;
    font-size: 11pt;
    color: #26221C;
}
.footer-row {
    position: absolute;
    bottom: 24mm;
    left: 28mm;
    right: 28mm;
    height: 30mm;
}
.instructors {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 90mm;
    font-size: 9pt;
    line-height: 1.3;
}
.instructors .label {
    color: #897E6E;
    text-transform: uppercase;
    letter-spacing: 1.5pt;
    font-size: 8pt;
}
.instructors .name {
    color: #26221C;
    font-weight: bold;
}
.issuer {
    position: absolute;
    bottom: 0;
    left: 100mm;
    width: 90mm;
    text-align: center;
    font-size: 9pt;
    line-height: 1.3;
}
.issuer .label {
    color: #897E6E;
    text-transform: uppercase;
    letter-spacing: 1.5pt;
    font-size: 8pt;
}
.issuer .name {
    color: #26221C;
    font-weight: bold;
}
.qr {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 30mm;
    text-align: right;
}
.qr img {
    width: 25mm;
    height: 25mm;
}
.qr .uuid {
    font-family: DejaVu Sans Mono, monospace;
    font-size: 6pt;
    color: #897E6E;
    margin-top: 1mm;
    text-align: right;
}
</style>
</head>
<body>
<div class="page">
    <div class="frame">
        <div class="frame-inner">
            <div class="divider top"></div>
            <div class="title">СЕРТИФІКАТ</div>
            <div class="divider bottom"></div>

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

            <div class="footer-row">
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

                <div class="qr">
                    <?php if ( '' !== $qr_data_uri ) : ?>
                    <img src="<?php echo htmlspecialchars( $qr_data_uri, ENT_QUOTES, 'UTF-8' ); ?>" alt="">
                    <?php endif; ?>
                    <div class="uuid"><?php echo htmlspecialchars( $verification_uuid, ENT_QUOTES, 'UTF-8' ); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
