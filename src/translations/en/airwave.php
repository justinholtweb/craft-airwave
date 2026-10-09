<?php
/**
 * English source strings.
 *
 * Present so translators have a complete list and so `Craft::t('airwave', …)` has a catalogue to
 * match against; English falls through to the key itself either way.
 */

return [
    // Elements
    'Feed' => 'Feed',
    'Feeds' => 'Feeds',
    'feed' => 'feed',
    'feeds' => 'feeds',
    'Channel' => 'Channel',
    'Channels' => 'Channels',
    'channel' => 'channel',
    'channels' => 'channels',
    'Items' => 'Items',
    'Untitled feed' => 'Untitled feed',
    'Untitled channel' => 'Untitled channel',

    // Health
    'Health' => 'Health',
    'Healthy' => 'Healthy',
    'Failing' => 'Failing',
    'Never fetched' => 'Never fetched',
    'OK' => 'OK',
    'Last checked' => 'Last checked',
    'Last error' => 'Last error',

    // Fields
    'Handle' => 'Handle',
    'Feed URL' => 'Feed URL',
    'Refresh every' => 'Refresh every',
    'Items to keep' => 'Items to keep',
    'URI' => 'URI',
    'Format' => 'Format',
    'Embed' => 'Embed',
    'Imports' => 'Imports',

    // Actions
    'New feed' => 'New feed',
    'New channel' => 'New channel',
    'Refresh now' => 'Refresh now',
    'Find the feed' => 'Find the feed',
    'Feed saved.' => 'Feed saved.',
    'Feed deleted.' => 'Feed deleted.',
    'Channel saved.' => 'Channel saved.',
    'Channel deleted.' => 'Channel deleted.',
    'Couldn’t save feed.' => 'Couldn’t save feed.',
    'Couldn’t save channel.' => 'Couldn’t save channel.',
    'All feeds refreshed.' => 'All feeds refreshed.',
    'Importing in the background.' => 'Importing in the background.',

    // Permissions
    'View feeds and items' => 'View feeds and items',
    'Add and edit feeds' => 'Add and edit feeds',
    'Delete feeds and items' => 'Delete feeds and items',
    'Refresh feeds' => 'Refresh feeds',
    'Import items as entries' => 'Import items as entries',
    'Publish and edit channels' => 'Publish and edit channels',

    // Editions
    'Airwave Lite holds up to {max} feeds.' => 'Airwave Lite holds up to {max} feeds.',
    'Airwave Lite publishes {max} channel.' => 'Airwave Lite publishes {max} channel.',
    'Filters are a Pro feature.' => 'Filters are a Pro feature.',
    'Importing is a Pro feature.' => 'Importing is a Pro feature.',
    'Atom and JSON Feed are Pro features.' => 'Atom and JSON Feed are Pro features.',

    // Errors
    'Enter a feed URL.' => 'Enter a feed URL.',
    'That does not look like a URL.' => 'That does not look like a URL.',
    'Feed URLs have to be http or https.' => 'Feed URLs have to be http or https.',
    'That does not parse as XML{detail}.' => 'That does not parse as XML{detail}.',
    'That does not parse as JSON.' => 'That does not parse as JSON.',
    'The server answered {status}.' => 'The server answered {status}.',
    'Nothing to show yet.' => 'Nothing to show yet.',
    'Read more' => 'Read more',
    'Only admins can change these templates, because they run as Twig on a public URL.' => 'Only admins can change these templates, because they run as Twig on a public URL.',
    'Only admins can add or change headers that use environment variables.' => 'Only admins can add or change headers that use environment variables.',
    'Only admins can use environment variables in request headers.' => 'Only admins can use environment variables in request headers.',
    'The “{name}” header is set by Airwave and can’t be overridden.' => 'The “{name}” header is set by Airwave and can’t be overridden.',
    'Order by an attribute such as `postDate` or `title`, or a field handle, followed by `asc` or `desc`.' => 'Order by an attribute such as `postDate` or `title`, or a field handle, followed by `asc` or `desc`.',
    'Feed health' => 'Feed health',
    'Feed failing' => 'Feed failing',
    'Feed not refreshing' => 'Feed not refreshing',
    'Every feed is refreshing.' => 'Every feed is refreshing.',
    'Last refresh failed' => 'Last refresh failed',
    'Last success' => 'Last success',
    'Alerts' => 'Alerts',
    'Email alerts to' => 'Email alerts to',
    'Slack or Teams webhook URL' => 'Slack or Teams webhook URL',
    'Webhook format' => 'Webhook format',
    'Webhook signing secret' => 'Webhook signing secret',
    'Alert when a feed keeps failing' => 'Alert when a feed keeps failing',
    'Failed refreshes in a row' => 'Failed refreshes in a row',
    'Quiet period after a recovery' => 'Quiet period after a recovery',
    'Send a test alert' => 'Send a test alert',
    'Uses the saved settings — save first.' => 'Uses the saved settings — save first.',
    'Save some recipients or a webhook URL first.' => 'Save some recipients or a webhook URL first.',
    'Email sent.' => 'Email sent.',
    'Email failed — check Craft’s email settings.' => 'Email failed — check Craft’s email settings.',
    'Webhook sent.' => 'Webhook sent.',
    'Webhook failed: {reason}' => 'Webhook failed: {reason}',
    'The test could not be sent.' => 'The test could not be sent.',
    '{count} refreshes in a row have failed. Latest: {error}' => '{count} refreshes in a row have failed. Latest: {error}',
    'No successful refresh since {since}.' => 'No successful refresh since {since}.',
    'Only http:// and https:// webhook URLs are allowed.' => 'Only http:// and https:// webhook URLs are allowed.',
    '“{address}” is not an email address.' => '“{address}” is not an email address.',
];
