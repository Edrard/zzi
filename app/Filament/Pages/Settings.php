<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.pages.settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'operator', 'viewer'], true);
    }

    protected function getHeaderActions(): array
    {
        if (auth()->user()->role === 'viewer') {
            return [];
        }

        return [
            Action::make('save')
                ->label('Save settings')
                ->action('save'),
        ];
    }

    public function mount(): void
    {
        $settings = Setting::query()->orderBy('key')->get();
        $initialData = [];

        foreach ($settings as $setting) {
            if ($setting->type === 'boolean') {
                $initialData[$setting->key] = $setting->value === 'true';
            } else {
                $initialData[$setting->key] = $setting->value;
            }
        }

        $this->form->fill($initialData);
    }

    public function form(Schema $schema): Schema
    {
        $settings = Setting::query()->orderBy('key')->get();
        $components = [];
        $isViewer = auth()->user()->role === 'viewer';

        foreach ($settings as $setting) {
            $label = Str::title(str_replace('_', ' ', $setting->key));

            if ($setting->type === 'boolean') {
                $components[] = Toggle::make($setting->key)
                    ->label($label)
                    ->helperText($setting->description)
                    ->disabled($isViewer)
                    ->required();
            } elseif ($setting->type === 'integer') {
                $min = 0;
                if ($setting->key === 'cleanup_batch_size') {
                    $min = 1;
                }

                $components[] = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($setting->description)
                    ->numeric()
                    ->integer()
                    ->minValue($min)
                    ->disabled($isViewer)
                    ->required();
            } elseif ($setting->type === 'json') {
                $components[] = Textarea::make($setting->key)
                    ->label($label)
                    ->helperText($setting->description)
                    ->rule('json')
                    ->disabled($isViewer)
                    ->required();
            } else {
                $components[] = TextInput::make($setting->key)
                    ->label($label)
                    ->helperText($setting->description)
                    ->disabled($isViewer)
                    ->required();
            }
        }

        $formComponents = [
            Section::make('System settings')
                ->description('Configure application behavior and retention limits.')
                ->schema($components)
                ->columns(1),
        ];

        if (! $isViewer) {
            $formComponents[] = Actions::make([
                Action::make('saveBottom')
                    ->label('Save settings')
                    ->action('save'),
            ])->alignEnd();
        }

        return $schema
            ->components($formComponents)
            ->statePath('data');
    }

    public function save(): void
    {
        if (auth()->user()->role === 'viewer') {
            abort(403, 'Viewers cannot modify settings.');
        }

        $data = $this->form->getState();
        $settings = Setting::query()->orderBy('key')->get();
        $changedSettings = [];

        foreach ($settings as $setting) {
            if (array_key_exists($setting->key, $data)) {
                $newValue = $data[$setting->key];

                if ($setting->type === 'boolean') {
                    $newValue = $newValue ? 'true' : 'false';
                } else {
                    $newValue = (string) $newValue;
                }

                if ($setting->value !== $newValue) {
                    $changedSettings[] = [
                        'key' => $setting->key,
                        'old_value' => $setting->value,
                        'new_value' => $newValue,
                    ];
                    $setting->update(['value' => $newValue]);
                }
            }
        }

        if (! empty($changedSettings)) {
            AuditLogger::log(
                action: 'settings.updated',
                entityType: 'settings',
                entityId: null,
                context: ['changes' => $changedSettings]
            );
        }

        Notification::make()
            ->title('Settings saved successfully.')
            ->success()
            ->send();
    }
}
