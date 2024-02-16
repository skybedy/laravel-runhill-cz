@inject('carbon', 'Carbon\Carbon')


<x-app-layout>

    <div class="py-3">
        <div class="max-w-7xl mx-auto sm:px-6 }lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-5">
                    @if (session('status'))
                        <div class="alert alert-success">
                            {{ session('status') }}
                        </div>
                    @endif

                    <div class="overflow-auto">
                        <table id="result_table" class="hidden md:table table-auto border-collapse w-full mt-5">


                        @foreach ($results as $result)
                            @if (count($result->results) > 0)
                                <tr><td class="text-center text-red-600 underline py-3 text-5xl">{{ $result->name }}</td><td colspan="3"></td></tr>
                                <tr class="text-center">
                                    <th class="border-none">Datum</th>
                                    <th class="border-none px-2">Čas</th>
                                    <th class="border-none px-2">Tempo</th>
                                    <th class="border-none px-2"></th>

                                </tr>

                                @foreach ($result->results as $result)
                                    <tr class="text-center odd:bg-gray-100 even:bg-white">
                                        <td class="border">{{ $carbon::parse($result['finish_time_date'])->format('j.n.') }}</td>
                                        <td class="border">{{ $result['finish_time'] }}</td>
                                        <td class="border">{{ $result['pace'] }}</td>
                                        <td class="border py-1">
                                            <form>
                                                <input type="hidden" name="result_id" value="{{ $result['id'] }}">
                                                <input type="submit" class="px-3 bg-red-500 border-2 border-red-600  text-white rounded cursor-pointer" value="Smazat">
                                            </form>
                                        </td>
                                    </tr>

                                @endforeach
                            @endif


                        @endforeach

                        </table>




            </div>
        </div>
    </div>
</x-app-layout>




