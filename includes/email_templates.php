<?php
/**
 * Email template rendering. Templates live in templates/email/{key}.php.
 *
 * Each template receives $vars (via extract) plus $pdo (passed directly),
 * sets $subject, and either:
 *   (a) echoes HTML output  — captured by ob_start/ob_get_clean, OR
 *   (b) sets $bodyHtml      — returned directly if ob output is empty.
 *
 * This dual-path approach means templates can either echo their HTML or
 * assign it to $bodyHtml (as the branded templates do via emailWrap()).
 * Both styles work correctly in web and CLI contexts.
 *
 * Optional $bodyText may be set by the template for the plain-text alternative.
 *
 * @param string $templateKey Template name (filename without .php)
 * @param array  $vars        Variables for the template
 * @param PDO|null $pdo       Database connection (passed into template scope)
 * @return array{subject: string, html: string, text: string|null}
 * @throws RuntimeException If template key is invalid or file is missing.
 */
function render_email_template(string $templateKey, array $vars = [], ?PDO $pdo = null): array
{
    $baseDir = dirname(__DIR__) . '/templates/email';
    $safeKey = preg_replace('/[^a-z0-9_]/', '', strtolower($templateKey));
    if ($safeKey === '') {
        throw new RuntimeException('Invalid email template key.');
    }
    $path = $baseDir . '/' . $safeKey . '.php';
    if (!is_file($path)) {
        throw new RuntimeException('Email template not found: ' . $templateKey);
    }

    $subject  = '';
    $bodyText = null;
    $bodyHtml = null;

    // Include in this function scope so $subject / $bodyHtml / $bodyText
    // assignments in the template always update these locals.
    ob_start();
    extract($vars, EXTR_SKIP);
    include $path;
    $obHtml = ob_get_clean();

    // Prefer $bodyHtml set by template (branded templates use emailWrap())
    // Fall back to ob-captured output (simple/legacy templates that echo HTML)
    $html = ($bodyHtml !== null && $bodyHtml !== '') ? $bodyHtml : $obHtml;

    if ($subject === '') {
        $subject = '(No subject)';
    }

    return [
        'subject' => $subject,
        'html'    => $html,
        'text'    => $bodyText,
    ];
}