    // Takes a table row element and an index and returns the normalized form
// of the sort attribute for the nth-child td. To be more clear, take the
// nth-child td element inside this table row as defined by index (that is
// `:nth-child(idx)`) and then normalize it's sort attribute (if it exists)
// otherwise use the internal text.
function sort_attr($tr, idx, asc) {
    var $td = $tr.children("td:nth-child(" + idx + ")"),
        sort_attr = $td.attr("sort")
    if (typeof(sort_attr) === "undefined") {
        sort_attr = $td.text()
    }

    // Normalize case
    sort_attr = sort_attr.trim().toLowerCase()

    // Try to treat this as an integer
    if(!sort_attr.match(/[a-z]/i)){
        if (sort_attr.trim() == "" && asc)
            return 99999999;
        if(sort_attr.trim() == "" && !asc)
            return -99999999;
        
        var int_attr = parseFloat(sort_attr);
        if (int_attr === 0 || int_attr && typeof(int_attr) == "number") {
            return int_attr
        }
    }
    // Guess we're using a string
    return sort_attr
}

// Returns a sorting function that can be applied to an array.
function _sort (idx, ascending) {
    return ascending ? function _sorter (a, b, asc=ascending) {
        return sort_attr($(a), idx, asc) > sort_attr($(b), idx, asc) ? 1 : -1;
    } : function _sorter (a, b, asc=ascending) {
        return sort_attr($(a), idx, asc) < sort_attr($(b), idx, asc) ? 1 : -1;
    }
}

// When clicking on a table header, perform some sorting.
function get_sorting_func(){
    $("table.table-comparator thead th").on("click", function () {
        var self = $(this)

        // Setup sort direction, defaulting to ascending and reversing
        // direction if previously set.
        var asc = self.attr("asc") == "true" ? false : true
        self.attr("asc", asc)

        // Clear all directions
        $(".dir").html("")

        // Setup current direction flag
        self.find(".dir").html(asc ? "&nbsp;(&#9650;)" : "&nbsp;(&#9660;)")

        // Sort!
        var fn = _sort(self.index() + 1, asc)
        $("table.table-comparator tbody").html($("table.table-comparator tbody tr").sort(fn))
    })
}
    