<?php

namespace Tests\Feature;

use App\Events\ShopUpdated;
use App\Models\Account;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\BusinessDays;
use App\Services\Orders;
use App\Support\LiveUpdates;
use App\Tenancy\TenantContext;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** M6: "something changed" messages per shop, and who may listen to them. */
class LiveUpdatesTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_a_sale_sends_one_message_to_its_shop_after_the_request(): void
    {
        $shop = $this->makeShop('blg');
        $this->enter($shop, $this->ownerOf($shop));
        app(LiveUpdates::class)->flush();
        Event::fake([ShopUpdated::class]);

        $order = $this->sell($shop);

        // Nothing is sent while the work is in progress...
        Event::assertNotDispatched(ShopUpdated::class);
        $this->assertSame([$shop->id => ['topics' => ['day', 'orders', 'money'], 'orders' => [$order->id]]], app(LiveUpdates::class)->pending());

        // ...and one message goes out when the request ends.
        app()->terminate();

        Event::assertDispatchedTimes(ShopUpdated::class, 1);
        Event::assertDispatched(ShopUpdated::class, function (ShopUpdated $event) use ($shop, $order) {
            return $event->shopId === $shop->id
                && $event->orders === [$order->id]
                && in_array('money', $event->topics, true)
                && $event->broadcastOn()[0]->name === 'private-shop.'.$shop->id
                && $event->broadcastAs() === 'shop.updated'
                && $event->broadcastWith() === ['topics' => $event->topics, 'orders' => [$order->id]];
        });
        $this->assertSame([], app(LiveUpdates::class)->pending());
    }

    public function test_a_sale_still_goes_through_when_reverb_is_down(): void
    {
        $shop = $this->makeShop('blg');
        $this->enter($shop, $this->ownerOf($shop));

        // Point broadcasting at a port nothing listens on.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 9, 'scheme' => 'http', 'useTLS' => false],
        ]);
        app(BroadcastManager::class)->purge('reverb');
        Log::spy();

        $order = $this->sell($shop);
        app(LiveUpdates::class)->flush();

        $this->assertSame('completed', $order->fresh()->status);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Live update not sent'))->once();
    }

    public function test_only_active_staff_of_the_shop_may_listen(): void
    {
        $a = $this->makeShop('aaa');
        $b = $this->makeShop('bbb');
        $this->useReverbForAuth();

        $owner = $this->ownerOf($a);
        $auth = fn (Shop $shop) => $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-shop.'.$shop->id]);

        $this->actingAs($owner, 'shop');
        $auth($a)->assertOk()->assertJsonStructure(['auth']);
        $auth($b)->assertForbidden();

        // A disabled login can't listen.
        $owner->update(['is_active' => false]);
        $this->actingAs($owner->fresh(), 'shop');
        $auth($a)->assertForbidden();

        // Nor can anyone while the shop is suspended.
        $owner->update(['is_active' => true]);
        $a->update(['is_active' => false]);
        $this->actingAs($owner->fresh(), 'shop');
        $auth($a)->assertForbidden();

        $this->actingAs($this->ownerOf($b), 'shop');
        $auth($b)->assertOk();
    }

    public function test_guests_and_super_admins_cannot_listen(): void
    {
        $shop = $this->makeShop('aaa');
        $this->useReverbForAuth();

        $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-shop.'.$shop->id])->assertUnauthorized();

        $admin = User::factory()->create(['is_super_admin' => true, 'tenant_id' => null]);
        $this->actingAs($admin, 'admin')
            ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-shop.'.$shop->id])
            ->assertUnauthorized();
    }

    public function test_the_pos_leaves_an_order_paid_on_another_till(): void
    {
        $shop = $this->makeShop('blg');
        $this->enter($shop, $this->ownerOf($shop));
        app(BusinessDays::class)->open(0);
        $product = $this->product();
        $order = app(Orders::class)->send(null, ['type' => 'takeaway'], [['variant_id' => $product->variants->first()->id, 'quantity' => 1]])->order;

        $pos = Livewire::test('pages::shop.pos', ['order' => $order]);

        // Another till's change to a different order: nothing happens here.
        $pos->dispatch('shop-updated', topics: ['orders'], orders: [$order->id + 100])->assertNoRedirect();

        // Paid elsewhere: the POS says so and opens the order's history.
        $this->checkout($order);
        $pos->dispatch('shop-updated', topics: ['orders', 'money'], orders: [$order->id])
            ->assertDispatched('toast', type: 'warning', message: $order->order_number.' was paid on another till.')
            ->assertRedirect(route('shop.orders.show', ['order' => $order->id]));
    }

    public function test_screens_include_the_live_settings_only_when_reverb_is_configured(): void
    {
        $shop = $this->makeShop('aaa');
        $this->actingAs($this->ownerOf($shop), 'shop');

        $this->get('/aaa/tabs')->assertOk()->assertDontSee('name="sfms-live"', false)->assertSee('Every minute');

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'public-key',
            'broadcasting.connections.reverb.client' => ['host' => 'pos.example.test', 'port' => 443, 'scheme' => 'https']]);

        $this->get('/aaa/tabs')->assertOk()
            ->assertSee('name="sfms-live"', false)
            ->assertSee('pos.example.test')
            ->assertSee('public-key')
            ->assertDontSee((string) config('broadcasting.connections.reverb.secret') ?: 'no-secret-configured');
    }

    // ---------------------------------------------------------------- helpers

    private function enter(Shop $shop, User $user): void
    {
        app(TenantContext::class)->set($shop);
        URL::defaults(['shop' => $shop->prefix]);
        $this->actingAs($user, 'shop');
    }

    private function product(): Product
    {
        $product = Product::create(['name' => 'Zinger']);
        $product->variants()->create(['name' => 'Regular', 'price' => 600, 'is_default' => true]);

        return $product->load('variants');
    }

    private function sell(Shop $shop)
    {
        app(BusinessDays::class)->open(0);
        $product = $this->product();
        $order = app(Orders::class)->send(null, ['type' => 'takeaway'], [['variant_id' => $product->variants->first()->id, 'quantity' => 1]])->order;

        return $this->checkout($order);
    }

    private function checkout($order)
    {
        $drawer = Account::query()->where('is_default_cash', true)->sole();

        return app(Orders::class)->checkout($order, [], [['account_id' => $drawer->id, 'amount' => 600]]);
    }

    /** Channel auth needs a real (signing) broadcaster; the tests otherwise use "null". */
    private function useReverbForAuth(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);

        $manager = app(BroadcastManager::class);
        $manager->purge('reverb');
        $manager->setDefaultDriver('reverb');
        require base_path('routes/channels.php');
    }
}
