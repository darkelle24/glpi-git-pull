<?php
namespace GlpiPlugin\Gitpull;

final class Web
{
    public static function guard(): void
    {
        \Session::checkRight('config', UPDATE);
        if (!(new \Plugin())->isActivated('gitpull')) {
            throw new \RuntimeException('Le plugin gitpull doit être activé.');
        }
        $_SESSION['gitpull_csrf'] ??= bin2hex(random_bytes(32));
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['gitpull_csrf'] ?? null;
            if (!is_string($token) || !hash_equals($_SESSION['gitpull_csrf'], $token)) {
                throw new \Glpi\Exception\Http\AccessDeniedHttpException();
            }
        }
    }

    public static function base(): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/gitpull/front/';
    }

    public static function render(string $template, array $data): void
    {
        $notice = $_SESSION['gitpull_notice'] ?? null;
        unset($_SESSION['gitpull_notice']);
        \Html::header('gitpull', $_SERVER['PHP_SELF'], 'config', Scheduler::class);
        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@gitpull/' . $template,
            $data + [
                'diagnostic' => Diagnostics::report(),
                'notice' => $notice,
                'csrf' => $_SESSION['gitpull_csrf'],
                'base' => self::base(),
            ]
        );
        \Html::footer();
    }
}
