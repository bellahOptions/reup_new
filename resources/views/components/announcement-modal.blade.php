@props(['announcements'])
@if($announcements->isNotEmpty())
    <div id="announcement-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-lg w-full">
            @foreach($announcements as $item)
                <div class="mb-4">
                    <div class="text-xl font-bold flex items-center space-x-2">
                        @if($item->icon)
                            <span>{{ $item->icon }}</span>
                        @endif
                        <span>{{ $item->title }}</span>
                    </div>
                    <p class="text-gray-700 mt-1">{{ $item->content }}</p>
                </div>
            @endforeach
            <button id="close-announcement" class="mt-4 px-4 py-2 bg-indigo-600 text-white rounded">Close</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('announcement-modal');
            const closeBtn = document.getElementById('close-announcement');

            if(modal) {
                closeBtn.addEventListener('click', () => modal.style.display = 'none');
            }
        });
    </script>
@endif
