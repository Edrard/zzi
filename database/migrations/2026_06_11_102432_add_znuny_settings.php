<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $settings = [
            ['key' => 'znuny_api_url', 'value' => 'https://otrs.vamark.net/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorREST', 'type' => 'string', 'description' => 'Znuny GenericTicketConnectorREST base URL'],
            ['key' => 'znuny_web_url', 'value' => 'https://otrs.vamark.net/otrs/index.pl', 'type' => 'string', 'description' => 'Znuny agent web interface URL'],
            ['key' => 'znuny_ticket_url_template', 'value' => 'https://otrs.vamark.net/otrs/index.pl?Action=AgentTicketZoom;TicketID={ticket_id}', 'type' => 'string', 'description' => 'Znuny agent ticket URL template'],
            ['key' => 'znuny_username', 'value' => '', 'type' => 'string', 'description' => 'Znuny integration agent login'],
            ['key' => 'znuny_password', 'value' => '', 'type' => 'string', 'description' => 'Znuny integration agent password'],
            ['key' => 'znuny_api_timeout', 'value' => '15', 'type' => 'integer', 'description' => 'Znuny API request timeout in seconds'],
            ['key' => 'znuny_api_verify_ssl', 'value' => 'true', 'type' => 'boolean', 'description' => 'Verify Znuny API SSL certificate'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::whereIn('key', [
            'znuny_api_url',
            'znuny_web_url',
            'znuny_ticket_url_template',
            'znuny_username',
            'znuny_password',
            'znuny_api_timeout',
            'znuny_api_verify_ssl',
        ])->delete();
    }
};
