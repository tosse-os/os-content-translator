# JobsRunner JSON Input Reproduction

This manual reproduction illustrates the regression protection added for JSON encoded `job_value` payloads.

1. From a WordPress shell (e.g. `wp shell`) bootstrap the plugin context so that the repositories can be instantiated.
2. Insert a dummy job row that stores its payload as JSON:
   ```php
   $repo = new OSCT\Domain\Repos\JobsRepo();
   $repo->upsert(
     'json-1',
     'de',
     'JSON Demo',
     wp_json_encode([
       'Bezeichnung' => 'Kurze Beschreibung',
       'Aufgaben' => '<p>Dies ist ein Testinhalt.</p>'
     ]),
     'json-demo',
     '',
     current_time('mysql')
   );
   ```
3. Execute the jobs runner with a fake translator so that the content is returned unchanged:
   ```php
   $runner = new OSCT\Translation\Jobs\JobsRunner(new OptionRepo(), new LanguageRepo(), new LogRepo());
   $runner->setTranslator(fn($text) => $text);
   $metrics = $runner->translateAll();
   ```
4. Confirm that the result metrics now show non-zero counts:
   ```php
   assert($metrics['words'] > 0);
   assert($metrics['chars'] > 0);
   ```

Prior to this change `job_value` was downgraded to an empty array and the metrics were reported as zero. The fallback to `json_decode()` keeps the structured content intact so the dashboard and logs display the correct totals.
