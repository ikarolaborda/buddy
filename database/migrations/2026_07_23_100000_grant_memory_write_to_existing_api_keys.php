<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Keys issued before the memory:write gate stored learnings implicitly
// under tasks:write; granting the scope explicitly keeps their behavior
// unchanged while new keys must opt in at issuance.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('api_keys')->get(['id', 'scopes']) as $row) {
            $scopes = json_decode((string) $row->scopes, true);

            if (! is_array($scopes) || in_array('memory:write', $scopes, true)) {
                continue;
            }

            $scopes[] = 'memory:write';

            DB::table('api_keys')->where('id', $row->id)->update([
                'scopes' => json_encode($scopes),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('api_keys')->get(['id', 'scopes']) as $row) {
            $scopes = json_decode((string) $row->scopes, true);

            if (! is_array($scopes)) {
                continue;
            }

            $filtered = array_values(array_filter($scopes, fn ($scope) => $scope !== 'memory:write'));

            if ($filtered !== $scopes) {
                DB::table('api_keys')->where('id', $row->id)->update([
                    'scopes' => json_encode($filtered),
                ]);
            }
        }
    }
};
