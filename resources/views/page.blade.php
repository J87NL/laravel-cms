@extends(config('cms.layout'))

@section('title', $page->meta_title ?: $page->title)

@section('content')
    <article class="cms-page">
        <h1 class="cms-page__title">{{ $page->title }}</h1>

        <x-cms::blocks :page="$page" />
    </article>
@endsection
