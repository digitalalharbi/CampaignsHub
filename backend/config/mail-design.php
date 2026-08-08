<?php

declare(strict_types=1);

/**
 * The email design system — MAIL-DS-001.
 *
 * ## Why a config file and not a stylesheet
 *
 * Email has no cascade worth relying on. Gmail keeps a `<style>` block on first view and drops it
 * when the message is forwarded or clipped; Outlook renders through Word and ignores most of what it
 * is given. So every value that matters has to be inlined on the element — which is exactly the
 * situation where the values drift, because inlining means repeating.
 *
 * They had already drifted: three templates carried fourteen distinct hex literals between them,
 * several of them one shade apart, and nothing said which was the border and which was the divider.
 * This file is the single answer, and `MailDesignSystemTest` fails when a template invents its own.
 *
 * ## Typography, and the thing most email design systems get wrong in Arabic
 *
 * The stack has to work with NO web font, because web fonts in email are blocked, unsupported or
 * stripped often enough that a design depending on one is a design that breaks for a large share of
 * readers. So the job is to name the best face already installed on each platform, in order.
 *
 * The Latin-first stack every template shipped — `-apple-system, Segoe UI, Roboto, Helvetica, Arial`
 * — has no Arabic face in it at all. Arabic text fell through to whatever the client picked, which on
 * Windows is usually Tahoma at a size tuned for Latin: the letterforms are cramped, the diacritics
 * collide, and long words break badly. Naming the Arabic faces first for RTL is the whole fix, and it
 * costs nothing for Latin because those families carry Latin glyphs too.
 */
return [
    /*
     * Colour, named by ROLE rather than by shade.
     *
     * A template asking for `border` cannot accidentally use the divider colour, which is what
     * happens when the palette is a list of hexes and the author picks the nearest one.
     */
    'colour' => [
        'brand' => '#0f766e',
        'brand_ink' => '#c6f0e9',   // on the brand background, in the header
        'ink' => '#0f172a',         // body text
        'ink_soft' => '#334155',    // secondary body text
        'muted' => '#5b6b68',       // labels
        'muted_soft' => '#8b9a97',  // footnotes and timestamps
        'canvas' => '#f1f5f4',      // outside the card
        'surface' => '#ffffff',     // the card itself
        'surface_soft' => '#f8fafa', // a panel inside the card
        'border' => '#e2e8e6',
        'divider' => '#eef2f1',
        'good' => '#0f766e',
        'good_bg' => '#ecfdf5',
        'warn' => '#b45309',
        'warn_bg' => '#fffbeb',
        'bad' => '#b91c1c',
        'bad_bg' => '#fef2f2',
    ],

    /*
     * Two stacks, because the reader's language decides which faces can render the words at all.
     *
     * Arabic order: Apple's system Arabic, then Segoe UI (which carries a good Arabic on Windows),
     * then Tahoma as the oldest reliable Windows Arabic, then Noto/Droid for Android and Linux.
     * Latin order: the platform UI faces every design system agrees on.
     *
     * `sans-serif` closes both, so a client with none of them still renders text rather than a
     * fallback nobody chose.
     */
    'font' => [
        'ar' => "'SF Arabic','Geeza Pro','Segoe UI',Tahoma,'Noto Sans Arabic','Droid Arabic Kufi',Arial,sans-serif",
        'en' => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
        /*
         * Figures.
         *
         * A digest is mostly numbers in columns, and proportional digits make «12,400» and «9,910»
         * different widths — so a column of money does not line up and the eye cannot compare down
         * it. These faces have tabular figures and are present nearly everywhere.
         */
        'numeric' => "'SF Mono',Menlo,Consolas,'Courier New',monospace",
    ],

    /*
     * The type scale — deliberately short.
     *
     * Six sizes, each with a job. A scale with twelve steps is one where the author picks by feel
     * and two templates end up a pixel apart for no reason a reader could name.
     *
     * Nothing below 12px: on a phone that is the floor at which a label is still readable, and
     * «small print» in an email about somebody's money is a choice, not a style.
     */
    'size' => [
        'display' => '24px',  // the one figure a message is about — an OTP, a headline KPI
        'title' => '18px',    // the subject of the message, restated in the body
        'heading' => '16px',  // a project or a section
        'body' => '14px',     // sentences
        'label' => '13px',    // table cells, secondary lines
        'caption' => '12px',  // footnotes, freshness, the unsubscribe
    ],

    /*
     * Line height, as a ratio.
     *
     * Arabic needs more than Latin at the same size: the diacritics sit above and below the
     * baseline, and a 1.4 that looks generous in English is crowded in Arabic. 1.8 for sentences is
     * the value that stopped looking tight in both.
     */
    'leading' => [
        'tight' => '1.3',   // headings, where a second line should feel attached to the first
        'body' => '1.8',    // sentences
    ],

    /** A four-step spacing scale. Anything finer is invisible in an email client's box model. */
    'space' => [
        'xs' => '4px',
        'sm' => '8px',
        'md' => '12px',
        'lg' => '16px',
        'xl' => '24px',
    ],

    'radius' => [
        'sm' => '8px',
        'md' => '12px',
        'lg' => '14px',
    ],

    /** 600px is what every client has agreed on for twenty years; the media query is the only override. */
    'width' => '600px',
];
