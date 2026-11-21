<?php

use App\Models\Customer;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::shop')] #[Title('Customers')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $phone = '';
    public string $address = '';
    public string $notes = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function shop()
    {
        return app(TenantContext::class)->shop();
    }

    #[Computed]
    public function customers()
    {
        $term = trim($this->search);
        $digits = Customer::normalizePhone($term);

        return Customer::query()
            ->when($term !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($term, $digits) {
                $q->where('name', 'like', "%{$term}%");

                if (strlen($digits) >= 3) {
                    $q->orWhere('phone', 'like', "%{$digits}%");
                }
            }))
            ->orderByDesc('last_order_at')
            ->orderBy('name')
            ->paginate(20);
    }

    public function openCreate(): void
    {
        $this->reset('editingId', 'name', 'address', 'notes');
        $this->phone = ctype_digit(Customer::normalizePhone($this->search)) && strlen(Customer::normalizePhone($this->search)) >= 7
            ? Customer::normalizePhone($this->search) : '';
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $customer = Customer::query()->findOrFail($id);

        $this->editingId = $customer->id;
        $this->name = $customer->name;
        $this->phone = $customer->phone;
        $this->address = (string) $customer->address;
        $this->notes = (string) $customer->notes;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->phone = Customer::normalizePhone($this->phone);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'digits_between:7,15',
                Rule::unique('customers', 'phone')->where('tenant_id', $this->shop->id)->ignore($this->editingId)],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'phone.unique' => 'A customer with this phone number already exists.',
            'phone.digits_between' => 'Enter a valid phone number.',
        ]);

        $attributes = [
            'name' => trim($this->name),
            'phone' => $this->phone,
            'address' => $this->address ?: null,
            'notes' => $this->notes ?: null,
        ];

        $this->editingId
            ? Customer::query()->findOrFail($this->editingId)->update($attributes)
            : Customer::create($attributes);

        $this->showModal = false;
        unset($this->customers);
        $this->dispatch('toast', type: 'success', message: $attributes['name'].' saved.');
    }

    public function delete(int $id): void
    {
        $customer = Customer::query()->findOrFail($id);
        $customer->delete();
        unset($this->customers);
        $this->dispatch('toast', type: 'success', message: $customer->name.' deleted.');
    }
};
?>

<div>
    <x-page-header title="Customers" subtitle="Saved by phone number. Typing a number on a delivery order fills in their details.">
        <x-slot:actions>
            <button type="button" class="btn btn-primary btn-touch" wire:click="openCreate"><i class="ri-user-add-line"></i>Add customer</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-0">
        <div class="card-header">
            <div class="form-icon">
                <input type="search" wire:model.live.debounce.300ms="search" class="form-control form-control-icon form-control-touch"
                       placeholder="Search by name or phone" aria-label="Search customers" inputmode="search">
                <i class="ri-search-2-line text-muted"></i>
            </div>
        </div>
        <div class="card-body p-0">
            @if ($this->customers->isEmpty())
                <x-empty-state icon="ri-contacts-book-3-line" title="{{ $search !== '' ? 'No matching customers' : 'No customers yet' }}">
                    @if ($search !== '')
                        <button type="button" class="btn btn-sm btn-primary mt-2" wire:click="openCreate">Add “{{ $search }}”</button>
                    @else
                        Customers are added here or automatically from delivery orders.
                    @endif
                </x-empty-state>
            @else
                <div class="table-responsive" wire:loading.class="opacity-50" wire:target="search,gotoPage,nextPage,previousPage">
                    <table class="table table-hover align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr><th>Customer</th><th>Address</th><th class="text-end">Orders</th><th class="text-end">Spent</th><th>Last order</th><th class="text-end"></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($this->customers as $customer)
                                <tr wire:key="customer-{{ $customer->id }}">
                                    <td class="td-primary">
                                        <div class="fw-semibold">{{ $customer->name }}</div>
                                        <a href="tel:{{ $customer->phone }}" class="fs-13 sfms-mono link-secondary">{{ $customer->phone }}</a>
                                        @if ($customer->notes)<div class="fs-12 text-muted">{{ $customer->notes }}</div>@endif
                                    </td>
                                    <td data-label="Address" class="fs-13" style="max-width: 18rem;">
                                        <div class="cell-stack text-truncate">{{ $customer->address ?: '—' }}</div>
                                    </td>
                                    <td data-label="Orders" class="text-end">{{ $customer->orders_count }}</td>
                                    <td data-label="Spent" class="text-end">{{ $this->shop->money($customer->total_spent) }}</td>
                                    <td data-label="Last order" class="fs-13">{{ $customer->last_order_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td class="text-end td-actions">
                                        <div class="hstack gap-2 justify-content-end">
                                            <button type="button" class="btn btn-sm btn-light" wire:click="openEdit({{ $customer->id }})"><i class="ri-pencil-line"></i> Edit</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="delete({{ $customer->id }})"
                                                    wire:confirm="Delete {{ $customer->name }}?" aria-label="Delete {{ $customer->name }}"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if ($this->customers->hasPages())
            <div class="card-footer">{{ $this->customers->links() }}</div>
        @endif
    </div>

    <x-modal wire:model="showModal" :title="$editingId ? 'Edit customer' : 'Add customer'">
        <form wire:submit="save" novalidate>
            <div class="modal-body vstack gap-3">
                <x-input model="phone" label="Phone" type="tel" required inputmode="tel" placeholder="0300 1234567" />
                <x-input model="name" label="Name" required />
                <x-textarea model="address" label="Delivery address" rows="2" placeholder="House, street, area" />
                <x-input model="notes" label="Notes" placeholder="e.g. Ring the bell twice, extra spicy" />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-touch" wire:loading.attr="disabled" wire:target="save">Save</button>
            </div>
        </form>
    </x-modal>
</div>
