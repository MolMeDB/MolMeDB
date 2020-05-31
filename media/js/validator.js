function load_duplicates(id, obj){
    var list = [];
    var labelValid = document.getElementById("labelAsValidated");
    var tab_list = document.getElementById("list_table");
    var ligand_1_1_id = document.getElementById("ligand_1_1_id");
    var table = document.getElementById("duplicates_table");
    var children = tab_list.children;
    var name = document.getElementById(id).innerHTML;
    for(var i = 0; i < children.length; i++){
        if (children[i].children[0].classList.contains("active"))
            children[i].children[0].classList.toggle("active");
    }
    
    obj.classList.toggle("active");
    table.innerHTML = '';
    var count;
    $.ajax({
      url: "AJAX/validator/load_duplicates.php?id=" + id,
      type: "GET",
      async: false,
      success: function(data){
              list = data.split("|");
              count = list.length;
              for(var i = 0; i<count; i++)
                  list[i] = list[i].split(";");
    }});
    
    for(var i = 0; i< count-1; i++){
       var tr = document.createElement("tr");
       var td = document.createElement("td");
       td.innerHTML = list[i][1] + " [id: " + list[i][0] + "]";
       td.setAttribute("onclick", "load_detail('" + id + "', '" + list[i][0] + "', this);");
       tr.appendChild(td);
       table.appendChild(tr);
       
    }
    
    labelValid.innerHTML = 'Label <b>' + name + '</b> as validated molecule <br/> <div style="color: red;">Warning! This choose will delete <b>' + name + '</b> from Possible duplicates list!</div>';
    ligand_1_1_id.value = id;
}



function load_detail(id1, id2, obj){
    var tab1 = document.getElementById("properties_1");
    var tab2 = document.getElementById("properties_2");
    var ligand_2_1_id = document.getElementById("ligand_2_1_id");
    var ligand_2_2_id = document.getElementById("ligand_2_2_id");
    var list = document.getElementById("duplicates_table");
    var joining = document.getElementById("joiningPairs");
    var detail_1 = [];
    var detail_2 = [];
    var alter_names_1;
    var alter_names_2;
    var headers = ["Name: ", "ID: ","MW: ", "Structure: ", "SMILES: ", "Alternative names: "];
    
    ligand_2_1_id.setAttribute("value", id1);
    ligand_2_2_id.setAttribute("value", id2);
    
    tab1.innerHTML = tab2.innerHTML = '';
    
    var children = list.children;
    for(var i = 0; i < children.length; i++){
        if (children[i].children[0].classList.contains("active"))
            children[i].children[0].classList.toggle("active");
    }
    obj.classList.toggle("active");
    
    $.ajax({
      url: "AJAX/validator/load_validation_data.php?id=" + id1,
      type: "GET",
      async: false,
      success: function(data){
              detail_1 = data.split(";");
    }});

    $.ajax({
          url: "AJAX/validator/load_validation_data.php?id=" + id2,
          type: "GET",
          async: false,
          success: function(data){
                  detail_2 = data.split(";");
    }});

    $.ajax({
      url: "AJAX/validator/load_alter_names.php?id=" + id1,
      type: "GET",
      async: false,
      success: function(data){
              if(data != '')
                  alter_names_1 = data;
              else
                  alter_names_1 = 'NO RECORDS';
    }});

    $.ajax({
          url: "AJAX/validator/load_alter_names.php?id=" + id2,
          type: "GET",
          async: false,
          success: function(data){
              if(data != '')
                  alter_names_2 = data;
              else
                  alter_names_2 = 'NO RECORDS';
    }});

    var smiles_1;
    var smiles_2;
    
    for(var i = 0; i< 6; i++){
       var tr = document.createElement("tr");
       var td = document.createElement("td");
       var td_data = document.createElement("td");
       
       td.innerHTML = headers[i];
       if(i < 5){
           if(i == 0)
               td_data.innerHTML = '<a href="detail/' + id1 + '">' + detail_1[i] + '</a>';
           if(i == 3){
               td_data.innerHTML = '<div class="col-md-12"  id="2dStructure_1"></div>';
               smiles_1 = detail_1[i];
           }
           else
               td_data.innerHTML = detail_1[i]
       }
       else
           td_data.innerHTML = alter_names_1;
       tr.appendChild(td);
       tr.appendChild(td_data);
       tab1.appendChild(tr);
    }
    
    for(var i = 0; i< 6; i++){
       var tr = document.createElement("tr");
       var td = document.createElement("td");
       var td_data = document.createElement("td");
       
       td.innerHTML = headers[i];
       if(i < 5){
           if(i == 0)
               td_data.innerHTML = '<a href="detail/' + id2 + '">' + detail_2[i] + '</a>';
           if(i == 3){
               td_data.innerHTML = '<div class="col-md-12"  id="2dStructure_2"></div>';
               smiles_2 = detail_2[i];
           }
           else
               td_data.innerHTML = detail_2[i];
       }
       else
           td_data.innerHTML = alter_names_2;
       tr.appendChild(td);
       tr.appendChild(td_data);
       tab2.appendChild(tr);
    }
       
    joining.innerHTML = 'Join <b>' + detail_1[0] + '</b> and <b>' + detail_2[0] + '</b>';
    
    //Načíst 2D struktury
    if(smiles_1 != '')
        update(smiles_1, '2dStructure_1');
    if(smiles_2 != '')
        update(smiles_2, '2dStructure_2');
}