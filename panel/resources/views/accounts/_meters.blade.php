@php($tm = $a->timeMeter())
@php($vm = $a->volumeMeter())
<x-rings :class="$class ?? ''" :tm="$tm" :vm="$vm" :used="$vm['used'] ?? 0" :expires-at="$a->expires_at"
  :volume-unlimited="(bool) ($a->charge() && ! $a->isVolumeLimited())"
  :status="\App\Models\VpnAccount::STATUS_LABELS[$a->status()] ?? null"/>
