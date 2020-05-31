    var btns_add = $('.btn-add');

    /**
     * Callback for adding/removing items to comparator list
     */
    $('.btn-add').each(function(index, obj)
    {
        // Add all button
        if(index === 0)
        {
            $(obj).click(function()
            {
                // Is already in list? Then remove
                if (this.classList.contains("btn-primary")) 
                {
                    this.innerHTML = "Delete list from the comparator";
                    for (i = 1; i < btns_add.length; i++) 
                    {
                        if (btns_add[i].classList.contains("btn-primary"))
                        {
                            btns_add[i].click();
                        }
                    }
                } 
                else // Else add 
                {
                    this.innerHTML = "Add list to the comparator";
                    for (i = 1; i < btns_add.length; i++) 
                    {
                        if (btns_add[i].classList.contains("btn-danger"))
                        {
                            btns_add[i].click();
                        }
                    }
                }
                this.classList.toggle("btn-primary");
                this.classList.toggle("btn-danger");
            })
        }
        else // Add/remove one 
        {
            // Set button ID
            var ID = $(obj).attr('id');
            $(obj).attr('id', get_search_list_id(ID));

            // Click callback
            $(obj).click(function()
            {
                var span = this.children[0];
                var name = span.id;

                if(is_in_comparator(ID))
                {
                    remove_from_comparator(ID);
                }
                else
                {
                    add_to_comparator(ID, name);
                }
            })
        }
    });

    /**
     *  Autocomplete callback for search engine form
     * 
     * @param {string} input
     * @param {string} type 
     */
    function autocomplete(input, type) 
    {
        var currentFocus;

        if(!input)
        {
            return;
        }

        /* execute a function when someone writes in the text field: */
        input.addEventListener("input", function(e) 
        {
            var a, b, i, val = this.value;
            var arr;
            
            /*close any already open lists of autocompleted values*/
            closeAllLists();
            if (!val || val.length < 3) 
            {
                return false;
            }
            currentFocus = -1;
            
            /*create a DIV element that will contain the items (values):*/
            a = document.createElement("DIV");
            a.setAttribute("id", this.id + "autocomplete-list");
            a.setAttribute("class", "autocomplete-items");
           
            /*append the DIV element as a child of the autocomplete container:*/
            this.parentNode.appendChild(a);

            // Get data
            var data = ajax_request("searchEngine/search", {
                q: val,
                type: type
            });

            if (data == false) 
            {
                return;
            }

            arr = data;

            // Show MAX 20 items
            var count;
            if (arr.length < 21) 
            {
                count = arr.length;
            } 
            else
            {
                count = 20;
            }

            // For each item, create row
            for (i = 0; i < count; i++) 
            {
                var j = 0;
                var limit = 0;

                var text = arr[i].name;
                text += arr[i].pattern != '' ? " - [" + arr[i].pattern + "]" : "";

                /*check if the item starts with the same letters as the text field value:*/
                while (!(text.substr(j, val.length).toUpperCase() == val.toUpperCase()) && j < text.length) 
                {
                    j++;
                }

                /*create a DIV element for each matching element:*/
                b = document.createElement("DIV");
                /*make the matching letters bold:*/
                b.innerHTML = text.substr(0, j);
                b.innerHTML += "<strong>" + text.substr(j, val.length) + "</strong>";
                b.innerHTML += text.substr(val.length + j);
                /*insert a input field that will hold the current array item's value:*/
                b.innerHTML += "<input type='hidden' value='" + arr[i].name + "'>";
                /*execute a function when someone clicks on the item value (DIV element):*/
                b.addEventListener("click", function(e) 
                {
                    /*insert the value for the autocomplete text field:*/
                    input.value = this.getElementsByTagName("input")[0].value;
                    /*close the list of autocompleted values,
                    (or any other open lists of autocompleted values:*/
                    closeAllLists();
                });
                a.appendChild(b);
            }
        });
        /*execute a function presses a key on the keyboard:*/
        input.addEventListener("keydown", function(e) 
        {
            var x = document.getElementById(this.id + "autocomplete-list");
            if (x) x = x.getElementsByTagName("div");
            if (e.keyCode == 40) 
            {
                /*If the arrow DOWN key is pressed,
                increase the currentFocus variable:*/
                currentFocus++;
                /*and and make the current item more visible:*/
                addActive(x);
            } 
            else if (e.keyCode == 38) 
            { //up
                /*If the arrow UP key is pressed,
                decrease the currentFocus variable:*/
                currentFocus--;
                /*and and make the current item more visible:*/
                addActive(x);
            } 
            else if (e.keyCode == 13) 
            {
                /*If the ENTER key is pressed, prevent the form from being submitted,*/
                //        e.preventDefault();
                if (currentFocus > -1) 
                {
                    /*and simulate a click on the "active" item:*/
                    if (x) x[currentFocus].click();
                }
            }
      });

      function addActive(x) 
      {
            /*a function to classify an item as "active":*/
            if (!x) return false;
            /*start by removing the "active" class on all items:*/
            removeActive(x);
            if (currentFocus >= x.length) currentFocus = 0;
            if (currentFocus < 0) currentFocus = (x.length - 1);
            /*add class "autocomplete-active":*/
            x[currentFocus].classList.add("autocomplete-active");
      }

      function removeActive(x) 
      {
            /*a function to remove the "active" class from all autocomplete items:*/
            for (var i = 0; i < x.length; i++) 
            {
                x[i].classList.remove("autocomplete-active");
            }
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

      /*execute a function when someone clicks in the document:*/
      document.addEventListener("click", function(e) 
      {
          closeAllLists(e.target);
      });
    }

    // Add autocomplete listeners
    autocomplete(document.getElementById("compoundSearch"), "compounds");
    autocomplete(document.getElementById("smilesSearch"), "smiles");
    autocomplete(document.getElementById("membraneSearch"), "membranes");
    autocomplete(document.getElementById("methodSearch"), "methods");