<footer class="mx-auto w-full max-w-md px-6 pb-8 text-center">
    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
        {{ __('auth.footer.copyright', ['year' => now()->year, 'brand' => config('relaticle.brand.name')]) }}
    </p>
</footer>
