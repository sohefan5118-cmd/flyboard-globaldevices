<?php
// Standalone transport, input via stdin so credentials never enter process argv.
require_once __DIR__.'/FlyboardUpstreamFetcher.php';
try {
    $p=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    echo json_encode((new \App\Services\FlyboardUpstreamFetcher)->fetchDirect($p['url'],$p['ua'],$p['timeout'],$p['maxBytes']),JSON_THROW_ON_ERROR);
} catch (\Throwable $e) { fwrite(STDERR,"Upstream retrieval failed\n"); exit(1); }
