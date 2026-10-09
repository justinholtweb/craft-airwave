<?php

namespace justinholtweb\airwave\events;

use craft\events\CancelableEvent;
use justinholtweb\airwave\elements\Source;

/**
 * Fired before a failing-feed alert (or its recovery) goes out.
 *
 * Change `subject`, `body` or `payload` to reword it; set `isValid` to false to swallow it — the
 * latch still counts it as sent, so a suppressed incident is not offered again on the next check.
 */
class AlertEvent extends CancelableEvent
{
    /** The feed, or null for a test alert. */
    public ?Source $source = null;

    /** One of the `Alerts::INCIDENT_*` constants, or `test`. */
    public string $incident = '';

    /** False for "this started", true for "this has cleared". */
    public bool $recovered = false;

    /** Redacted before it reaches the event. */
    public string $detail = '';

    public string $subject = '';

    /** The plain-text email body. */
    public string $body = '';

    /** The webhook body, already shaped for the configured format (Slack, Teams or JSON). */
    public array $payload = [];
}
