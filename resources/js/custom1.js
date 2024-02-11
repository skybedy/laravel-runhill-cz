import $ from 'jquery';

$(() => {

     // $('a.test_tr').on('click', function(e) {
      $('table#result_table').on('click','a.result_map',function(e){
            e.preventDefault();



                $(".tr_map").remove();





           // $('[id^="dynamic_result_individual_"]').remove();

            var url = $(this).attr('href');
            $.getJSON(url, function(response) {
                  var gpxx = zacatek;
                  gpxx += response;
                  gpxx += konec;
                  var value = gpxx.trim();


                  var m = new SMap(JAK.gel("m"));
                  var xmlDoc = JAK.XML.createDocument(value);

                  var gpx = new SMap.Layer.GPX(xmlDoc, null, {maxPoints:500}); /* GPX vrstva */
                  m.addDefaultLayer(SMap.DEF_BASE).enable();
                  m.addLayer(gpx); /* Přidáme ji do mapy */
                  m.addDefaultControls();
                  gpx.enable();    /* Zapnout vrstvu */
                  gpx.fit();
              }).fail(function(xhr) {
                  // Zpracování chyb
                  console.error(xhr);
              });




           var trId =  $(this).closest('tr').attr('id');
           if($(window).width() < 640)
           {
            var novyRadek = $('<tr id="tr_map"><td class="text-center" colspan="5"><div id="m" style="height:300px"></div></td></tr>'); // Vytvoření nového řádku
            $("#result_table_sm #"+trId).after(novyRadek);

           }
           else
           {
            var novyRadek = $('<tr class="tr_map" style="position:relative"><td class="text-center" colspan="9"><div id="close_map" style="padding:2px 10px;border:2px solid black;background:white;position:absolute;left:10px;top:10px;z-index:1000;cursor:pointer">Zavřít</div><div id="m" style="height:400px"></div></td></tr>'); // Vytvoření nového řádku
            $("#result_table #"+trId).after(novyRadek);

           }


      });



      $(document).on('click', '.tr_map','#close_map', function()
      {
            $(this).remove();
      });




var zacatek = '<?xml version="1.0" encoding="UTF-8"?><gpx><trk><trkseg>';

var konec = '</trkseg></trk></gpx>';

















$(document).on('click', 'a[href*="/event/result/"]', function(e) {

    e.preventDefault(); // Zabraňte výchozímu chování

    $(".tr_map").remove();


    if($('[id^="dynamic_result_individual_"]').length > 0)
    {
        $('[id^="dynamic_result_individual_"]').remove()

        return;
    }

    var hrefValue = $(this).attr('href'); // Získání hodnoty atributu href

    var resultId = hrefValue.match(/result\/(\d+)/)[1]; // Získání čísla za slovem "result" a za lomítkem

    var trId =  $(this).closest('tr').attr('id');



    $.getJSON($(this).attr('href'), function(response) {

        var str = "";

        for (var key in response) {

            if (response.hasOwnProperty(key)) {

                var value = response[key];

                str += '<tr id="dynamic_result_individual_'+ key +'" class="bg-red-100 text-blue-700">';

                str += '<td class="border" colspan="4"></td>';

                str += '<td class="border px-2 text-center">'+response[key].date+'</td>'

                str += '<td class="border text-center"><a class="result_map flex justify-center" href="/result/'+ resultId +'/map"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="blue" class="w-6 h-6"><path fill-rule="evenodd" d="m7.539 14.841.003.003.002.002a.755.755 0 0 0 .912 0l.002-.002.003-.003.012-.009a5.57 5.57 0 0 0 .19-.153 15.588 15.588 0 0 0 2.046-2.082c1.101-1.362 2.291-3.342 2.291-5.597A5 5 0 0 0 3 7c0 2.255 1.19 4.235 2.292 5.597a15.591 15.591 0 0 0 2.046 2.082 8.916 8.916 0 0 0 .189.153l.012.01ZM8 8.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" clip-rule="evenodd" /></svg></a></td>';

                str += '<td class="border text-center">'+response[key].pace+'</td>';

                str += '<td class="border text-center">'+response[key].finish_time+'</td>';

                str += '<td class="border text-center"></td>';

                str += '</tr>';





            }
        }

        if($(window).width() < 640)
        {
           $("#result_table_sm #"+trId).after(str);

        }
        else
        {
            $("#result_table #"+trId).after(str);

        }

    }).fail(function(xhr) {
        // Zpracování chyb
        console.error(xhr);
    });
  });










});
