<div {!! $attributes !!}>
    <ul class="nav nav-tabs dcat-tabs-nav {{ $tabStyle }} {{ $searchable ? 'dcat-tabs-searchable' : '' }}" role="tablist">
        @foreach($tabs as $id => $tab)
            @if($tab['type'] == \Dcat\Admin\Widgets\Tab::TYPE_CONTENT)
                <li class="nav-item" >
                    <a href="#tab_{{ $tab['id'] }}" class=" nav-link  {{ $id == $active ? 'active' : '' }}" data-toggle="tab" data-tab-search-text="{{ $tab['search'] ?? '' }}">{!! $tab['title'] !!}</a>
                </li>
            @elseif($tab['type'] == \Dcat\Admin\Widgets\Tab::TYPE_LINK)
                <li class="nav-item" >
                    <a href="{{ $tab['href'] }}" class=" nav-link  {{ $id == $active ? 'active' : '' }}" data-tab-search-text="{{ $tab['search'] ?? '' }}">{!! $tab['title'] !!}</a>
                </li>
            @endif
        @endforeach

        @if (!empty($dropDown))
        <li class="dropdown nav-item">
            <a class="dropdown-toggle nav-link" data-toggle="dropdown" href="#">
                Dropdown <span class="caret"></span>
            </a>
            <ul class="dropdown-menu">
                @foreach($dropDown as $link)
                <li role="presentation"><a role="menuitem" tabindex="-1" href="{{ $link['href'] }}">{!! $link['name'] !!}</a></li>
                @endforeach
            </ul>
        </li>
        @endif
        <li class="nav-item pull-right header">{!! $title !!}</li>
        @if($searchable)
        <li class="dcat-tab-search" role="presentation">
            <button type="button" class="btn btn-sm btn-icon dcat-tab-search-toggle" data-tab-search-toggle disabled
                    aria-label="{{ trans('admin.tab_search') }}" title="{{ trans('admin.tab_search') }}"
                    aria-expanded="false" aria-controls="{{ $searchId }}">
                <i class="feather icon-search" aria-hidden="true"></i>
            </button>
            <div class="card dcat-tab-search-panel" id="{{ $searchId }}" data-tab-search-panel hidden>
                <label for="{{ $searchId }}-input" class="sr-only">{{ trans('admin.tab_search') }}</label>
                <input type="search" id="{{ $searchId }}-input" class="form-control form-control-sm" data-tab-search-input
                       placeholder="{{ trans('admin.tab_search') }}" autocomplete="off" aria-controls="{{ $searchId }}-results">
                <div id="{{ $searchId }}-results" class="dcat-tab-search-results" data-tab-search-results></div>
                <div class="text-muted dcat-tab-search-empty" data-tab-search-empty role="status" hidden>{{ trans('admin.tab_no_results') }}</div>
            </div>
        </li>
        @endif
    </ul>

    <div class="tab-content" style="{!! $padding !!}">
        @foreach($tabs as $id => $tab)
        <div class="tab-pane {{ $id == $active ? 'active' : '' }}" id="tab_{{ $tab['id'] }}">
            {!! $tab['content'] ?? '' !!}
        </div>
        @endforeach

    </div>
</div>
