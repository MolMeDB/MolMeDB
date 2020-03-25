/**
 * Finds 3D structure on remote servers
 * 
 * @param {string} db 
 * @param {string|integer} id 
 */
function find3DStructure(db, id)
{
    var id = document.getElementById(db).value;
    var field = document.getElementById("fileURL");
    var post_inp = document.getElementById("post_url");
    
    switch(db)
    {
        case "pdb":
            field.setAttribute("value", "https://files.rcsb.org/ligands/view/" + id + "_model.sdf");
            post_inp.setAttribute("value", "https://files.rcsb.org/ligands/view/" + id + "_model.sdf");
            break;
        case "pubchem":
            field.setAttribute("value", "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/" + id + "/record/SDF/?record_type=3d&response_type=display");
            post_inp.setAttribute("value", "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/" + id + "/record/SDF/?record_type=3d&response_type=display");
            break;
        case "drugbank":
            field.setAttribute("https://www.drugbank.ca/structures/small_molecule_drugs/" + id + ".mol");
            post_inp.setAttribute("https://www.drugbank.ca/structures/small_molecule_drugs/" + id + ".mol");
            break;
        case "chEBI":
            field.setAttribute("http://www.ebi.ac.uk/chebi/saveStructure.do?defaultImage=true&chebiId=" + id + "&imageId=0");
            post_inp.setAttribute("http://www.ebi.ac.uk/chebi/saveStructure.do?defaultImage=true&chebiId=" + id + "&imageId=0");
            break;    
    }
}


/**
 * Loads missing data from pubchem server
 * 
 * temporary unavailable
 */
function loadMissingData()
{
    alert('Sorry, service is temporary unavailable.');
    return;

    var used = [];
    pubchem_id = document.getElementById("pubchem").value;
    
    if(!pubchem_id){
        alert("Please fill pubchemID id.");
        return;
    }
 
    data = findPubchem(pubchem_id);
    
    document.getElementById("pdb").value = dbs[1];
    document.getElementById("pubchem").value =  dbs[0];
    document.getElementById("chEBI").value = dbs[2];
    document.getElementById("chEMBL").value = dbs[3];
    document.getElementById("drugbank").value = dbs[4];
    document.getElementById("MW").value = dbs[5];
    document.getElementById("SMILES").value = dbs[6];
    if(dbs[7] == '')
        document.getElementById("Area").value = 0;
    else 
       document.getElementById("Area").value = dbs[7];
    if(dbs[8] == '')
        document.getElementById("Volume").value = 0;
    else 
       document.getElementById("Volume").value = dbs[8];
   if(dbs[9] == '' || dbs[9] === 'undefined')
        document.getElementById("LogP").value = 0;
    else 
       document.getElementById("LogP").value = dbs[9];
}

// function findPubchem(id, i){
//     var newid;
//     var object;
//     $.ajax({
//         url: "https://pubchem.ncbi.nlm.nih.gov/rest/pug_view/data/compound/" + id.toString() + "/JSON/?response_type=display",
//         type: "GET",
//         async: false,
//         success: function(data){
//             object = JSON.parse(data);
//         }
//     });
             
//     switch(i){
//         case 0:
//             newid = '';
//             break;
//         case 1:
//             newid = '';
//             break;
//         case 2:
//             newid = '';
//             break;
//         case 3:
//             newid = '';
//             break;
//         case 4:
//             var s1 = 0;
//             var size = object.Record.Reference.length;
//             if(size == 0)
//                 break;
//             while(s1 != size && object.Record.Reference[s1].SourceName != 'DrugBank' ){
//                 s1++;                  
//             }
//             if (size != s1){
//                 newid = object.Record.Reference[s1].SourceID;                       
//             }
//             else
//                 newid='';
//             break;
//         case 5:
//             var s1 = 0; var s2 = 0;
//             var size = object.Record.Section.length;
//             while(s1 != size && object.Record.Section[s1].TOCHeading != 'Chemical and Physical Properties'){
//                 s1++;                    
//             }
            
//             var size2 = object.Record.Section[s1].Section.length;
            
//             if (size != s1){
//                 while(s2 != size2 && object.Record.Section[s1].Section[s2].TOCHeading != 'Computed Properties'){
//                     s2++;                    
//                 }
//             }
//             newid = object.Record.Section[s1].Section[s2].Information[0].Table.Row[0].Cell[1].NumValue; 
               
//             break;
//         case 6:
//             var s1 = 0;
//             var s2 = 0;
//             var s3 = 0;
//             var size;

//             while(object.Record.Section[s1].TOCHeading !== "Names and Identifiers")
//                 s1++;
//             while(object.Record.Section[s1].Section[s2].TOCHeading !== "Computed Descriptors")
//                 s2++;

//             size = object.Record.Section[s1].Section[s2].Section.length;

//             while((s3 !== size) && (object.Record.Section[s1].Section[s2].Section[s3].TOCHeading !== "Isomeric SMILES"))
//                 s3++;

//             if (s3 === size){
//                 s3 = 0;
//                 while(object.Record.Section[s1].Section[s2].Section[s3].TOCHeading !== "Canonical SMILES")
//                     s3++;
//             }

//             newid=object.Record.Section[s1].Section[s2].Section[s3].Information[0].StringValue;
//             break;
//         case 7:
//             newid = '';
//             break;
//         case 8:
//             newid = '';
//             break;
//         case 9:
//             var s1 = 0;
//             var s2 = 0;
//             var s3 = 0;
//             var size;
//             var regex = /[+-]?\d+(\.\d+)?/g;
            
//             while(object.Record.Section[s1].TOCHeading !== "Chemical and Physical Properties")
//                 s1++;
//             while(object.Record.Section[s1].Section[s2].TOCHeading !== "Experimental Properties")
//                 s2++;
//             size = object.Record.Section[s1].Section[s2].Section.length;

//             while((s3 !== size) && (object.Record.Section[s1].Section[s2].Section[s3].TOCHeading !== "LogP"))
//                 s3++;
//             if(s3 !== size){
//                 if(newid = (object.Record.Section[s1].Section[s2].Section[s3].Information[0].StringValue)){
//                     newid = newid.replace("at 25 deg C", "");
//                     newid = newid.match(regex).map(function(v) { return parseFloat(v); });
//                 }
//                 else if(newid = object.Record.Section[s1].Section[s2].Section[s3].Information[0].NumValue)
//                     var j;
//                 else
//                     newid='';
//             }
//             break;
//     }
//     return newid;
// }
