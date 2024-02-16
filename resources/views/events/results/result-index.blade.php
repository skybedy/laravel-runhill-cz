<x-app-layout>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <x-event-navbar :event="$event" />
                    <div class="overflow-auto">
                        <table id="result_table" class="hidden md:table table-auto border-collapse w-full mt-5">
                            <tr class="">
                                <th class="border-none">Pořadí</th>
                                <th class="border-none text-left px-2">Jméno</th>
                                <th class="border-none text-left px-2">Tým-Město-Obec</th>
                                <th class="border-none text-left px-2">Kategorie</th>
                                <th class="border-none text-center px-2">Datum</th>
                                <th class="border-none text-center px-2">Místo</th>
                                <th class="border-none">Tempo</th>
                                <th class="border-none">Čas</th>
                                <th class="border-none">Rozdíl</th>
                            </tr>
                            @foreach ($results as $result )
                                @if($loop->iteration == 1)
                                    @php
                                        $best_time = $result->best_finish_time_sec;
                                    @endphp
                                @endif
                                <tr class="odd:bg-gray-100 even:bg-white" id="result_{{ $result->id }}">
                                    <td class="border text-center">{{ $loop->iteration }}</td>
                                    <td class="border px-2">
                                        @if($result->count > 1)
                                            <a href="{{ route('result.user',$result->registration_id) }}" class="text-blue-700 underline">{{ $result->lastname }} {{ $result->firstname }}</a>
                                        @else
                                        {{ $result->lastname }} {{ $result->firstname }}
                                        @endif
                                    </td>
                                    <td class="border px-2"> {{ $result->team }}</td>
                                    <td class="border px-2">{{ $result->category_name }}</td>
                                    <td class="border px-2 text-center">{{ $result->date }}</td>
                                    <td class="border px-2">
                                        <a class="result_map flex justify-center" href="{{ route('result.map',$result->id) }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="red" class="w-6 h-6">
                                                <path fill-rule="evenodd" d="m7.539 14.841.003.003.002.002a.755.755 0 0 0 .912 0l.002-.002.003-.003.012-.009a5.57 5.57 0 0 0 .19-.153 15.588 15.588 0 0 0 2.046-2.082c1.101-1.362 2.291-3.342 2.291-5.597A5 5 0 0 0 3 7c0 2.255 1.19 4.235 2.292 5.597a15.591 15.591 0 0 0 2.046 2.082 8.916 8.916 0 0 0 .189.153l.012.01ZM8 8.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" clip-rule="evenodd" />
                                            </svg>
                                        </a>
                                    </td>

                                    <td class="border text-center">{{ $result->pace }}</td>
                                    <td class="border text-center">{{ $result->best_finish_time }}</td>
                                    <td class="border text-center">{{ dynamic_distance($loop->iteration,$result->best_finish_time_sec,$best_time) }}</td>
                                </tr>
                            @endforeach
                        </table>

                        <table id="result_table_sm" class="md:hidden table-auto border-collapse w-full mt-5 text-sm">
                            <tr>
                                <th class="border-none">#</th>
                                <th class="border-none text-left px-2">Jméno</th>
                                <th class="border-none text-left px-2">Dat</th>
                                <th class="border-none text-left px-2">Map</th>
                                <th class="border-none">Čas</th>
                                <th class="border-none">Tempo</th>
                            </tr>
                            @foreach ($results as $result )
                                @if($loop->iteration == 1)
                                    @php
                                        $best_time = $result->best_finish_time_sec;
                                    @endphp
                                @endif
                                <tr class="odd:bg-gray-100 even:bg-white" id="result_{{ $result->id }}">
                                    <td class="border text-center">{{ $loop->iteration }}</td>
                                    <td class="border px-2">
                                        @if($result->count > 1)
                                            <a href="{{ route('result.user',$result->registration_id) }}" class="text-blue-700 underline">{{ $result->lastname }} {{ $result->firstname }}</a>
                                        @else
                                        {{ $result->lastname }} {{ $result->firstname }}
                                        @endif
                                    </td>
                                    <td class="border px-2">{{ $result->date }}</td>
                                    <td class="border px-2"><a class="result_map flex justify-center" href="{{ route('result.map',$result->id) }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="red" class="w-6 h-6">
                                                <path fill-rule="evenodd" d="m7.539 14.841.003.003.002.002a.755.755 0 0 0 .912 0l.002-.002.003-.003.012-.009a5.57 5.57 0 0 0 .19-.153 15.588 15.588 0 0 0 2.046-2.082c1.101-1.362 2.291-3.342 2.291-5.597A5 5 0 0 0 3 7c0 2.255 1.19 4.235 2.292 5.597a15.591 15.591 0 0 0 2.046 2.082 8.916 8.916 0 0 0 .189.153l.012.01ZM8 8.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" clip-rule="evenodd" />
                                            </svg></a></td>
                                    <td class="border text-center">{{ $result->best_finish_time }}</td>
                                    <td class="border text-center">{{ $result->pace }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</x-app-layout>








