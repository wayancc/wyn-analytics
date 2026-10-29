<?php
/**
 * Add-to-site instructions.
 * @var WynAnalytics\View $v @var array $site @var string $snippet @var string $script @var string $ignore @var string $event
 */
$v->layout('layout/admin', [
    'heading' => $site['name'],
    'crumb'   => '<a href="' . $v->url('admin') . '">Sites</a> / ' . $v->e($site['domain']),
    'tabsFor' => $site,
]);
$sid = $site['id'];
?>
<ol class="wyn-steps">
  <li>
    <h2>Paste the script</h2>
    <p>Add this line before <code>&lt;/head&gt;</code> on every page of <?= $v->e($site['domain']) ?>. It’s about 2 KB, loads after the page, and sets no cookies or visitor IDs.</p>
    <div class="wyn-code"><button class="wyn-copy" type="button" data-wyn-copy-next>Copy</button><pre><code><?= $v->e($snippet) ?></code></pre></div>
    <?php if ($site['first_event_at'] === null): ?>
      <p class="wyn-waiting" data-wyn-wait="<?= $v->e($v->url("admin/sites/{$sid}/now.json")) ?>" role="status"><span class="wyn-pip" aria-hidden="true"></span>Waiting for the first visit. Open your site in another tab; this page updates by itself.</p>
    <?php else: ?>
      <p class="wyn-waiting" role="status"><span class="wyn-pip is-live" aria-hidden="true"></span>Receiving visits since <?= $v->e($v->time((int) $site['first_event_at'], 'M j, Y')) ?>. <a href="<?= $v->url("admin/sites/{$sid}") ?>">Open the dashboard</a></p>
    <?php endif ?>
    <p class="wyn-small">Nothing showing up? Visits from <code>localhost</code>, from bots and from browsers with Global Privacy Control turned on aren’t counted. The response header <code>X-Wyn-Result</code> on the request to <code><?= $v->e($event) ?></code> says why an event was skipped.</p>
  </li>

  <li>
    <h2>Leave out your own visits</h2>
    <p>Open <a href="<?= $v->e($ignore) ?>"><?= $v->e($ignore) ?></a> once in each browser you use. That browser is then ignored on this site (visit <code>#wyn-track</code> to undo). Or exclude your connection in <a href="<?= $v->url("admin/sites/{$sid}/settings#exclude") ?>">settings</a>.</p>
  </li>

  <li id="events">
    <h2>Count what matters</h2>
    <p>Name any button or link with <code>data-wyn-event</code>. Extra <code>data-wyn-prop-*</code> attributes are saved with it.</p>
    <div class="wyn-code"><button class="wyn-copy" type="button" data-wyn-copy-next>Copy</button><pre><code>&lt;a href="/book" data-wyn-event="Booking" data-wyn-prop-room="Garden suite"&gt;Book now&lt;/a&gt;</code></pre></div>
    <p>From your own JavaScript:</p>
    <div class="wyn-code"><button class="wyn-copy" type="button" data-wyn-copy-next>Copy</button><pre><code>Wyn.event('Signup', { plan: 'Pro' });</code></pre></div>
    <p>Then add the event as a <a href="<?= $v->url("admin/sites/{$sid}/settings#goals") ?>">goal</a> to see its conversion rate.</p>
  </li>

  <li>
    <h2>Optional extras</h2>
    <p>Add these attributes to the script tag:</p>
    <ul>
      <li><code>data-wyn-outbound</code> counts clicks on links to other websites, as the event “Outbound link”.</li>
      <li><code>data-wyn-downloads</code> counts clicks on PDF, ZIP, DOCX and other files, as “File download”.</li>
      <li><code>data-wyn-local</code> also counts visits on localhost, for testing.</li>
    </ul>
    <p><strong>Wyn Forms</strong>: forms that send with <code>data-wyn-ajax</code> are counted as “Form submitted” automatically. For classic forms, make a pageview goal for your thank-you page. <strong>Single-page apps</strong> (React, Vue, Astro view transitions) are tracked without extra setup.</p>
  </li>
</ol>
