{{-- Sidebar menu list. $menu comes from App\Support\Navigation. --}}
<ul class="main-menu" @if (! empty($id)) id="{{ $id }}" @endif role="menu">
    @foreach ($menu as $group)
        <li class="menu-title" role="presentation">{{ $group['title'] }}</li>

        @foreach ($group['items'] as $item)
            <li class="slide">
                @if ($item['href'])
                    <a href="{{ $item['href'] }}"
                       class="side-menu__item"
                       role="menuitem"
                       wire:navigate.hover
                       @if (! empty($item['exact'])) wire:current.exact="active" @else wire:current="active" @endif>
                        <span class="side_menu_icon"><i class="{{ $item['icon'] }}"></i></span>
                        <span class="side-menu__label">{{ $item['label'] }}</span>
                    </a>
                @else
                    <a href="#!"
                       class="side-menu__item is-soon"
                       role="menuitem"
                       aria-disabled="true"
                       title="Coming in {{ $item['soon'] }}"
                       onclick="return false">
                        <span class="side_menu_icon"><i class="{{ $item['icon'] }}"></i></span>
                        <span class="side-menu__label">{{ $item['label'] }}</span>
                        <span class="side-menu__badge">Soon</span>
                    </a>
                @endif
            </li>
        @endforeach
    @endforeach
</ul>
