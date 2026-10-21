<?php

declare(strict_types=1);

namespace App\Domains\Tracking\Services;

/**
 * TRACKING-EVENTS-001 — decides whether an open was a person or a machine, for MEASUREMENT only.
 *
 * It never decides what the visitor is shown. Every short link answers everybody with the same single
 * response (SHORT-LINK-COMPLIANCE-001): a person, an ad platform's review bot and a chat app's link preview
 * all get the same redirect. This class is called AFTER that response is fixed, to decide whether the open
 * counts as a person's. A tracker that served different content to bots would be cloaking; one that only
 * stops counting them is arithmetic.
 *
 * What it can and cannot know: a user agent is a claim. A well-behaved crawler or link preview announces
 * itself and is caught here; a client that lies is indistinguishable from a person, so "not a bot" means
 * "did not announce itself as one", and the numbers built on it say so.
 */
final class BotClassifier
{
    /**
     * Link-preview fetchers: a person shared the link, a machine opened it.
     *
     * Deliberately NOT listed: the in-app browsers of Snapchat, TikTok, Instagram and Facebook. Their user agents
     * name the app, and the person using it is exactly who an ad click is measured for — flagging them would
     * remove real customers from the count.
     */
    private const PREVIEW = ['facebookexternalhit', 'facebot', 'whatsapp/', 'telegrambot', 'slackbot', 'slack-imgproxy', 'discordbot', 'twitterbot', 'linkedinbot', 'skypeuripreview', 'embedly', 'quora link preview', 'pinterestbot'];

    private const CRAWLER = ['googlebot', 'bingbot', 'duckduckbot', 'baiduspider', 'yandexbot', 'applebot', 'semrushbot', 'ahrefsbot', 'mj12bot', 'petalbot', 'bytespider', 'adsbot', 'mediapartners', 'google-read-aloud', 'storebot', 'ia_archiver', 'adsreview', 'crawler', 'spider', 'bot/', 'bot;', ' bot'];

    private const TOOL = ['curl/', 'wget/', 'python-requests', 'python-urllib', 'go-http-client', 'java/', 'okhttp', 'apache-httpclient', 'libwww-perl', 'node-fetch', 'axios/', 'postmanruntime', 'insomnia', 'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium', 'lighthouse', 'pingdom', 'uptimerobot', 'statuscake'];

    /**
     * @return array{is_bot: bool, reason: ?string, family: string}
     */
    public static function classify(?string $userAgent): array
    {
        $ua = mb_strtolower(trim((string) $userAgent));

        if ($ua === '') {
            return ['is_bot' => true, 'reason' => 'missing_user_agent', 'family' => 'none'];
        }
        foreach (self::PREVIEW as $needle) {
            if (str_contains($ua, $needle)) {
                return ['is_bot' => true, 'reason' => 'link_preview', 'family' => 'preview'];
            }
        }
        foreach (self::CRAWLER as $needle) {
            if (str_contains($ua, $needle)) {
                return ['is_bot' => true, 'reason' => 'declared_crawler', 'family' => 'crawler'];
            }
        }
        foreach (self::TOOL as $needle) {
            if (str_contains($ua, $needle)) {
                return ['is_bot' => true, 'reason' => 'automation_tool', 'family' => 'tool'];
            }
        }

        return ['is_bot' => false, 'reason' => null, 'family' => self::family($ua)];
    }

    private static function family(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'edg/') => 'edge',
            str_contains($ua, 'opr/') || str_contains($ua, 'opera') => 'opera',
            str_contains($ua, 'firefox') => 'firefox',
            str_contains($ua, 'chrome') || str_contains($ua, 'crios') => 'chrome',
            str_contains($ua, 'safari') => 'safari',
            default => 'other',
        };
    }
}
