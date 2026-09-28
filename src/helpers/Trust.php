<?php

declare(strict_types=1);

namespace justinholtweb\airwave\helpers;

use Craft;
use craft\web\View;

/**
 * What only an admin may author, and how it is rendered once authored.
 *
 * Two things in Airwave are more powerful than the permission that edits them looks:
 *
 * - **Twig templates** (channel mappings, import titles and field maps). Unsandboxed Twig can read
 *   `craft.app.config.general.securityKey`, query every user, and include any template on disk. A
 *   channel is served on a public URL, so whatever a template prints, the internet can read.
 * - **Environment references in request headers** (`$FEED_TOKEN`, `${VAR}`, `@alias`). Resolving
 *   them is the point of the feature, but a header is sent to whatever server the feed URL names,
 *   so `X-Anything: $CRAFT_SECURITY_KEY` on a feed pointing at your own server is a way of reading
 *   `.env`.
 *
 * Both are treated the way Craft treats its own Twig-bearing settings: writable by admins only.
 * Everyone else can edit everything around them, and their saves keep what is already stored.
 */
final class Trust
{
    /** Whether the current user may author templates and environment references. */
    public static function canAuthorCode(): bool
    {
        $request = Craft::$app->getRequest();

        // Console commands and queue jobs run as whoever has shell access, which is more than admin.
        if ($request->getIsConsoleRequest()) {
            return true;
        }

        return (bool)Craft::$app->getUser()->getIdentity()?->admin;
    }

    /**
     * Whether a string asks `App::parseEnv()` to substitute something.
     *
     * Checked by shape rather than by resolving it, so a reference to a variable that happens to be
     * unset on this environment is still recognised as one.
     */
    public static function referencesEnvironment(string $value): bool
    {
        return (bool)preg_match('/\$\{\w+\}|(?:^|\/)\$\w+(?:$|\/)|^@/', trim($value));
    }

    /**
     * Render an object template, sandboxed if the site has opted into Craft's Twig sandbox.
     *
     * `renderSandboxedObjectTemplate()` exists from Craft 5.9 and honours the `enableTwigSandbox`
     * setting; on older versions, or with the setting off, this is the plain render — which is why
     * authoring is admin-only rather than relying on the sandbox.
     */
    public static function renderObjectTemplate(string $template, mixed $object, array $variables = []): string
    {
        $view = Craft::$app->getView();

        if (method_exists($view, 'renderSandboxedObjectTemplate')) {
            return $view->renderSandboxedObjectTemplate($template, $object, $variables, View::TEMPLATE_MODE_SITE);
        }

        return $view->renderObjectTemplate($template, $object, $variables, View::TEMPLATE_MODE_SITE);
    }

    /** As {@see renderObjectTemplate()}, for a plain template string. */
    public static function renderString(string $template, array $variables = []): string
    {
        $view = Craft::$app->getView();

        if (method_exists($view, 'renderSandboxedString')) {
            return $view->renderSandboxedString($template, $variables, View::TEMPLATE_MODE_SITE);
        }

        return $view->renderString($template, $variables, View::TEMPLATE_MODE_SITE);
    }
}
