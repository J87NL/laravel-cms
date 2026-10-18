@extends(config('cms.layout'))

@section('title', $title)

@section('head')
    <x-cms::seo :page="$page" :preview="$preview ?? false" />
@endsection

@section('content')
    <article class="cms-page">
        <h1 class="cms-page__title">{{ $page->title }}</h1>

        <x-cms::blocks :page="$page" />
    </article>
@endsection
