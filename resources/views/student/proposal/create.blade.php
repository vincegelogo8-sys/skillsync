<x-app-layout>
    <x-slot name="header"><h1 class="font-semibold text-xl text-gray-800">{{ __('Upload Research Proposal') }}</h1></x-slot>
    <x-slot name="description">{{ __('Submit your research title and a proposal document to begin analysis.') }}</x-slot>
    <div class="py-12"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <section class="bg-white p-4 shadow sm:rounded-lg sm:p-8">
            <form method="POST" action="{{ route('student.proposals.store') }}" enctype="multipart/form-data" class="max-w-2xl space-y-6">
                @csrf
                <p class="text-sm text-gray-600">{{ __('Enter your research title and upload a PDF or DOCX document, up to 10 MB. Your document is accessible to you and Admin.') }}</p>
                @php $title = old('title'); @endphp
                <div>
                    <x-input-label for="title" :value="__('Research Title')" />
                    <x-text-input id="title" name="title" type="text" required maxlength="255" class="mt-1 block w-full" :value="is_string($title) ? $title : ''"
                        aria-describedby="title_error" :aria-invalid="$errors->has('title') ? 'true' : 'false'" />
                    <x-input-error id="title_error" :messages="$errors->get('title')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="document" :value="__('Proposal Document')" />
                    <input id="document" name="document" type="file" required accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                        class="mt-2 block w-full text-sm text-gray-700" aria-describedby="document_help document_error" aria-invalid="{{ $errors->has('document') ? 'true' : 'false' }}">
                    <p id="document_help" class="mt-2 text-sm text-gray-600">{{ __('PDF or DOCX only. Maximum 10 MB. If validation fails, choose your file again before resubmitting.') }}</p>
                    <x-input-error id="document_error" :messages="$errors->get('document')" class="mt-2" />
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <x-primary-button>{{ __('Upload Proposal') }}</x-primary-button>
                    <a href="{{ route('student.proposals.index') }}" class=" button button-secondary">{{ __('Cancel') }}</a>
                </div>
            </form>
        </section>
    </div></div>
</x-app-layout>
