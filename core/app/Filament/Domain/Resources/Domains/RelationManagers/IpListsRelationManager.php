<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Filament\Domain\Resources\Domains\RelationManagers\Concerns\SyncsDomainPage;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\IpList;
use App\Support\DomainIpLists;
use App\Support\FilamentHelp;
use App\Support\IpListData;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class IpListsRelationManager extends RelationManager
{
    use SyncsDomainPage;

    protected static string $relationship = 'ipLists';

    protected static ?string $title = 'IP lists';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return number_format($ownerRecord instanceof Domain ? $ownerRecord->ipLists()->count() : 0);
    }

    public function form(Schema $schema): Schema
    {
        $maximum = IpListData::maximumEntries();

        return $schema->columns(1)->components([
            TextInput::make('name')->label(FilamentHelp::label('Name', 'Firewall rules refer to the list by this name. Lower case letters, digits, hyphens and underscores. It cannot be changed later.'))
                ->required()->maxLength(40)->regex(IpListData::NAME_PATTERN)->disabled(fn (?IpList $record): bool => $record !== null)->placeholder('office'),
            TextInput::make('description')->label('Description')->maxLength(250)->placeholder('Addresses of the office networks'),
            Textarea::make('entries')->label(FilamentHelp::label('Addresses and networks', "One IPv4 or IPv6 address or CIDR network per line, up to $maximum. Host bits of a network are cleared and duplicates are merged."))
                ->rows(12)->required()->placeholder("203.0.113.0/24\n198.51.100.9\n2001:db8:abcd::/48")
                ->formatStateUsing(fn (mixed $state): string => is_array($state) ? implode("\n", $state) : (string) $state),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('List')->searchable()->description(fn (IpList $record): ?string => $record->description)->wrap(),
            TextColumn::make('entry_count')->label('Entries')->numeric()->alignEnd(),
            TextColumn::make('used')->label(FilamentHelp::label('Used by', 'Firewall rules that test visitors against this list. A list used by no enabled rule is not sent to the edge.'))
                ->state(function (IpList $record): string {
                    $count = count(IpListData::rulesUsing($this->domain(), $record->name));

                    return $count === 0 ? 'No rules' : $count.' '.str('rule')->plural($count);
                }),
            TextColumn::make('updated_at')->label('Changed')->since(),
        ])->headerActions([
            CreateAction::make()->label('Add list')->modalHeading('Add IP list')->modalSubmitActionLabel('Add list')->slideOver()->modalWidth(Width::TwoExtraLarge)->createAnother(false)
                ->visible(fn (): bool => $this->domain()->ipLists()->count() < (int) config('security.ip_lists.maximum_lists_per_domain'))
                ->using(fn (array $data): IpList => $this->saveList($data['name'], $data, 'ip_list.saved')),
        ])->recordActions([
            EditAction::make()->modalHeading('Edit IP list')->modalSubmitActionLabel('Save list')->slideOver()->modalWidth(Width::TwoExtraLarge)
                ->using(fn (IpList $record, array $data): IpList => $this->saveList($record->name, $data, 'ip_list.saved')),
            DeleteAction::make()->modalHeading('Delete this list?')->using(function (IpList $record): bool {
                $using = IpListData::rulesUsing($this->domain(), $record->name);
                if ($using !== []) {
                    Notification::make()->danger()->title('The list is used by '.count($using).' firewall '.str('rule')->plural(count($using)))
                        ->body('Delete or change '.(count($using) === 1 ? 'it' : 'them').' first.')->send();

                    return false;
                }
                AuditLog::record(auth()->user(), 'ip_list.deleted', $this->domain(), ['name' => $record->name, 'entries' => $record->entry_count], request()->ip());

                return (bool) $record->delete();
            }),
        ])->defaultSort('name')->emptyStateHeading('No IP lists')
            ->emptyStateDescription('A list holds up to '.number_format(IpListData::maximumEntries()).' addresses or networks that firewall rules can allow, block or challenge by name.');
    }

    private function saveList(string $name, array $data, string $audit): IpList
    {
        $text = (string) ($data['entries'] ?? '');
        $lines = preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        try {
            $entries = IpListData::normalize($lines, 'data.entries');
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['mountedActions.0.data.entries' => collect($exception->errors())->flatten()->first()]);
        }
        [$list] = DomainIpLists::save($this->domain(), $name, fn (?IpList $current): array => ['entries' => $entries, 'description' => $data['description'] ?? null], auth()->user(), request()->ip(), $audit);

        return $list;
    }

    private function domain(): Domain
    {
        $domain = $this->getOwnerRecord();
        assert($domain instanceof Domain);

        return $domain;
    }
}
