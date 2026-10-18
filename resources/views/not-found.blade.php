@extends(config('cms.layout'))

@section('title', __('cms::public.not_found.title'))

@section('content')
    <article class="cms-page cms-page--not-found">
        <h1 class="cms-page__title">{{ __('cms::public.not_found.title') }}</h1>

        <p>{{ __('cms::public.not_found.text') }}</p>

        <p><a href="/">{{ __('cms::public.not_found.home') }}</a></p>
    </article>
@endsection
