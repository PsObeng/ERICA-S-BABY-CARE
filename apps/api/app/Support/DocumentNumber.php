<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe document numbers, e.g. RCPT-2026-000214 (DOC-004).
 * Must be called inside the business transaction so a rolled-back sale releases nothing twice.
 * Offline devices use their own prefix (RCPT-D2-000045) so numbers never collide.
 */
final class DocumentNumber
{
    public static function next(string $type, ?string $devicePrefix = null): string
    {
        $year = (int) now('Africa/Accra')->format('Y');
        $seqType = $devicePrefix ? substr($type, 0, 4).$devicePrefix : $type;

        $row = DB::table('document_sequences')->where(['type' => $seqType, 'year' => $year])->lockForUpdate()->first();
        if (! $row) {
            DB::table('document_sequences')->insertOrIgnore(['type' => $seqType, 'year' => $year, 'next_value' => 1]);
            $row = DB::table('document_sequences')->where(['type' => $seqType, 'year' => $year])->lockForUpdate()->first();
        }
        DB::table('document_sequences')->where(['type' => $seqType, 'year' => $year])->update(['next_value' => $row->next_value + 1]);

        return $devicePrefix
            ? sprintf('%s-%s-%06d', $type, $devicePrefix, $row->next_value)
            : sprintf('%s-%d-%06d', $type, $year, $row->next_value);
    }
}
