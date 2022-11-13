    var btns_add = $('.btn-add');
    var ketcher = null; 

    function init_ketcher()
    {
        if(ketcher)
        {
            return;
        }

        var ketcherFrame = document.getElementById('ketcher');

        if ('contentDocument' in ketcherFrame)
            ketcher = ketcherFrame.contentWindow.ketcher;
        else // IE7
            ketcher = document.frames['ifKetcher'].window.ketcher;
    }

    /**
     * Finds molecules by smiles
     */
    $('#search_smiles').on('click', async function(el)
    {
        init_ketcher();

        if(!ketcher)
        {
            return;
        }

        var smiles = await ketcher.getSmiles();

        if(!smiles)
        {
            return; // TODO
        }

        $('<form method="GET" action="/search/smiles/1"><input name="q" value="' + smiles + '"></form>').appendTo('body').submit();
    });

    $('#insert_structure').on('click', function(e)
    {
        init_ketcher();

        let smiles = $('#insert_smiles').val();

        if(!smiles || !ketcher)
        {
            return;
        }

        ketcher.setMolecule(smiles);
    });

    var loading = false;

    /**
     *  Autocomplete callback for search engine form
     * 
     * @param {string} input
     * @param {string} type 
     */
    function autocomplete(input, type) 
    {
        if(!input)
        {
            return;
        }

        var val = "";
        var timeout, timeout_done = false;
        var pending = false;

        /* execute a function when someone writes in the text field: */
        input.addEventListener("input", async function(e) 
        {
            val = this.value;
            t = this;
            
            if(loading)
            {
                pending = true;
                return false;
            }
            else if(!timeout_done)
            {
                // TODO: Show loader
                closeAllLists();
                if(timeout)
                {
                    window.clearTimeout(timeout);
                }
                timeout = window.setTimeout(function() {
                    timeout_done = false;
                    whisper(t);
                }, 500);
            }
        });

        async function whisper(input)
        {
            pending = false;
            if (!val || val.length < 3) 
            {
                hideLoader();
                return false;
            }

            var a, b, i;
            var arr;
            currentFocus = -1;
            
            /*create a DIV element that will contain the items (values):*/
            a = document.createElement("DIV");
            a.setAttribute("id", input.id + "autocomplete-list");
            a.setAttribute("class", "autocomplete-items");
            
            /*append the DIV element as a child of the autocomplete container:*/
            input.parentNode.appendChild(a);
            
            showLoader(a);

            // Load data
            $.ajax({
                url: '/api/search/all',
                method: "GET",
                dataType: "json",
                headers:{
                    "Authorization": "Basic " + $('#api_internal_token').val(),
                    Accept: "application/json"
                },
                data: {'type': type, "query": val.trim()},
                async: true,
                success: function(data)
                {
                    if(pending)
                    {
                        whisper(input);
                        return;
                    }

                    if (!data || data == false) 
                    {
                        hideLoader();
                        return;
                    }

                    arr = data;

                    // Show MAX 20 items
                    var count = arr.length;

                    if(count > 0 && arr[0].identifier)
                    {
                        expanded = true;
                    }
                    else
                    {
                        expanded = false;
                    }

                    // For each item, create row
                    for (i = 0; i < count; i++) 
                    {
                        var j = 0;
                        var text = arr[i].name + " [" + arr[i].uniprot_id + "]";

                        if(arr[i].pattern)
                        {
                            text += arr[i].pattern != '' ? " - [" + arr[i].pattern + "]" : "";
                        }

                        /*check if the item starts with the same letters as the text field value:*/
                        while (!(text.substr(j, val.length).toUpperCase() == val.toUpperCase()) && j < text.length) 
                        {
                            j++;
                        }

                        if(expanded)
                        {

                            b = $('<div class="autocomplete-item"></div>');
                            $('<div class="autocomplete-item-content"></div>').append(
                                $('<div></div>').append(
                                    $('<div class="autocomplete-item-title"><p>' + arr[i].name + '</p></div>')
                                ).append(
                                    $('<table style="width:fit-content;"></table>').append(
                                        [...$('<tr><th>ID:</th><td>' + arr[i].identifier + '</td></tr>'),
                                        $('<tr><th>Pubchem ID:</th><td>' + arr[i].pubchem + '</td></tr>'),
                                        $('<tr><th>Drugbank ID:</th><td>' + arr[i].drugbank + '</td></tr>'),
                                        $('<tr><th>Chembl ID:</th><td>' + arr[i].chembl + '</td></tr>'),
                                        arr[i].altername ? $('<tr><th>Alternative name:</th><td>' + arr[i].altername + '</td></tr>') : null,
                                            $('<tr><th>MW:</th><td>' + arr[i].mw + '</td></tr>'),
                                            $('<tr><th>InchiKey:</th><td>' + arr[i].inchikey + '</td></tr>')
                                        ]
                                    )
                                )).append(
                                    $('<div>' + arr[i].img + '</div>')
                                ).appendTo(b);

                                $('<input type="hidden" value="' + arr[i].identifier + '">').appendTo(b);

                                $(b).on('click', function(e) 
                                {
                                    let v = $(this).find('input')[0];
                                    v = $(v).val();

                                    redirect('mol/' + v);
                                });

                                $(a).append(b);
                        }
                        else
                        {
                            /*create a DIV element for each matching element:*/
                            b = document.createElement("DIV");
                            /*make the matching letters bold:*/
                            b.classList.add("autocomplete-item-short")
                            b.innerHTML = text.substr(0, j);
                            b.innerHTML += "<strong>" + text.substr(j, val.length) + "</strong>";
                            b.innerHTML += text.substr(val.length + j);
                            b.innerHTML += "<input type='hidden' value='" + arr[i].name + "'>";
                            /*execute a function when someone clicks on the item value (DIV element):*/
                            b.addEventListener("click", function(e) 
                            {
                                /*insert the value for the autocomplete text field:*/
                                let val = this.getElementsByTagName("input")[0].value;
                                // Send form
                                $('<form method="GET" action="/search/' + type + '/1"></form>').append(
                                    $('<input type="hidden" name="q" value="' + val + '">')
                                ).appendTo('body').submit();
                            });
                            a.appendChild(b);
                        }
                    }

                    let btn = $('<button type="submit" class="btn btn-warning">Show more...</button>');

                    // Show more button
                    let form = $('<div class="autocomplete-show-all"></div>').append(
                        $('<form action="/search/' + type + '/1" method="GET">').append(
                            $('<input type="hidden" value="' + val + '" name="q">')
                        ).append(
                            btn
                        )
                    );

                    $(form).appendTo(a);
                    hideLoader();
                },
                error: function(){
                    if(pending)
                    {
                        whisper(input);
                        return;
                    }
                    hideLoader();
                }
            })
        }


      function closeAllLists(elmnt) 
      {
            /*close all autocomplete lists in the document,
            except the one passed as an argument:*/
            var x = document.getElementsByClassName("autocomplete-items");
            for (var i = 0; i < x.length; i++) 
            {
                if (elmnt != x[i] && elmnt != input) 
                {
                    x[i].parentNode.removeChild(x[i]);
                }
            }
      }

      function showLoader(target)
        {
            hideLoader();
            loading = true;
            var el = $('<div id="se-loader"><div style="display:flex; flex-direction:row; justify-content:center; align-items:center; height:150px; width:100%;">' +
            '<div style="display:flex; flex-direction: column;">' +
                '<div class="ripple-loader">' +
                '<div></div>' +
                '<div></div>' +
                '</div>' +
                '<div style="color: grey;">' +
                'Searching...' +
                '</div>' +
            '</div>' +
            '</div>' +
        '</div>')

        $(target).append(el);
        }

        function hideLoader()
        {
            $('#se-loader').remove();
            loading = false;
        }

      /*execute a function when someone clicks in the document:*/
      document.addEventListener("click", function(e) 
      {
            if(e.target.classList.contains("btn-warning"))
            {
                return;
            }
            closeAllLists(e.target);
      });
    }

    // Add autocomplete listeners
    autocomplete(document.getElementById("compoundSearch"), "compound");
    autocomplete(document.getElementById("transporterSearch"), "transporter");