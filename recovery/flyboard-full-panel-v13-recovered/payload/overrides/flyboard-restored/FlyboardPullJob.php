<?php
namespace App\Services;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\V2\Admin\FlyboardController;

/** Payload contains only IDs/options, never subscription credentials. */
class FlyboardPullJob implements ShouldQueue
{
    use Queueable;
    public $tries=1;
    public $timeout=45;
    public $failOnTimeout=true;
    public function __construct(public int $upstreamId, public string $run, public int $requestedTimeout, public int $maxBytes, public int $queuedAt)
    { $this->onConnection('redis')->onQueue('flyboard_upstream'); }
    private static function cache() { return Cache::store('redis'); }
    private static function key(int $id): string { return 'flyboard:pull:v2:'.$id; }
    public static function state(int $id): array
    {
        $s=self::cache()->get(self::key($id), ['status'=>'idle']);
        if (in_array($s['status'],['pending','running'],true) && time()-$s['queued_at']>600) return ['status'=>'error','error'=>'Background pull expired; retry','run'=>$s['run']];
        return $s;
    }
    public static function enqueue(int $id,int $timeout,int $maxBytes): array
    {
        $lock=self::cache()->lock(self::key($id).':submit',10);
        if (!$lock->get()) return ['id'=>$id,'status'=>'pending','duplicate'=>true];
        try {
            $s=self::state($id);
            if (in_array($s['status'],['pending','running'],true)) return array_merge($s,['id'=>$id,'duplicate'=>true]);
            $run=bin2hex(random_bytes(16)); $at=time();
            $s=['id'=>$id,'run'=>$run,'status'=>'pending','queued_at'=>$at];
            self::cache()->put(self::key($id),$s,86400);
            try { app('queue')->connection('redis')->push(new self($id,$run,$timeout,$maxBytes,$at), '', 'flyboard_upstream'); }
            catch (\Throwable $e) { self::cache()->put(self::key($id),array_merge($s,['status'=>'error','error'=>'Queue unavailable; retry']),86400); throw new \RuntimeException('Background queue unavailable'); }
            return $s;
        } finally { $lock->release(); }
    }
    private function publish(array $data): void
    {
        $s=self::cache()->get(self::key($this->upstreamId),[]);
        if (($s['run']??null)===$this->run) self::cache()->put(self::key($this->upstreamId),array_merge($s,$data),86400);
    }
    public function handle(): void
    {
        $s=self::state($this->upstreamId);
        if (($s['run']??null)!==$this->run || $s['status']!=='pending') return;
        if (time()-$this->queuedAt>540) { $this->failed(); return; }
        $lock=self::cache()->lock(self::key($this->upstreamId).':execute',65);
        if (!$lock->get()) return;
        try {
            $this->publish(['status'=>'running','started_at'=>time()]);
            $r=app(FlyboardController::class)->importUpstream($this->upstreamId,$this->requestedTimeout,$this->maxBytes);
            $this->publish(array_merge($r,['status'=>'completed','finished_at'=>time()]));
        } catch (\Throwable $e) { $this->failed(); }
        finally { $lock->release(); }
    }
    public function failed(?\Throwable $exception=null): void
    {
        // Intentionally do not log raw transport exceptions (URLs may contain secrets).
        $error='Upstream import failed or timed out; existing nodes retained';
        $this->publish(['status'=>'error','error'=>$error,'finished_at'=>time()]);
        DB::table('flyboard_upstream_users')->where('id',$this->upstreamId)->update(['last_error'=>$error]);
    }
}
