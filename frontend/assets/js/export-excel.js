/**
 * Utility function to export HTML table or data to Excel (.csv / .xls)
 * Handles French UTF-8 accents and custom column exclusion (e.g. actions buttons)
 */
function exportTableToExcel(tableId, filename = 'export_mandel.csv') {
    const table = document.getElementById(tableId);
    if (!table) {
        alert("Tableau introuvable pour l'exportation.");
        return;
    }

    let csv = [];
    const rows = table.querySelectorAll("tr");

    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        
        // Skip hidden filter/drawer rows
        if (row.classList.contains("d-none") || row.style.display === "none" || row.classList.contains("no-export")) {
            continue;
        }

        const cols = row.querySelectorAll("th, td");
        let rowData = [];

        for (let j = 0; j < cols.length; j++) {
            const col = cols[j];

            // Exclude action columns or no-print / no-export cells
            if (col.classList.contains("no-print") || col.classList.contains("no-export")) {
                continue;
            }

            let text = col.innerText || col.textContent || "";
            // Clean up multiline text and extra spaces
            text = text.replace(/(\r\n|\n|\r)/gm, " ").replace(/\s+/g, " ").trim();
            // Escape double quotes
            text = text.replace(/"/g, '""');
            
            rowData.push('"' + text + '"');
        }

        if (rowData.length > 0) {
            csv.push(rowData.join(";")); // Semicolon delimiter for Excel French locale
        }
    }

    if (csv.length === 0) {
        alert("Aucune donnée à exporter.");
        return;
    }

    // Add UTF-8 BOM byte sequence so Excel opens accents properly
    const csvContent = "\uFEFF" + csv.join("\n");
    const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });

    if (navigator.msSaveBlob) { // IE 10+
        navigator.msSaveBlob(blob, filename);
    } else {
        const link = document.createElement("a");
        if (link.download !== undefined) {
            const url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", filename.endsWith('.csv') ? filename : filename + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
}
