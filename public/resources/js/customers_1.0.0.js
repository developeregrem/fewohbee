function initCityLookup() {
    let zipElements = document.getElementById("accordion").querySelectorAll('input[name*="zip-"]');
    zipElements.forEach(element => {
        addAutocompleteCityLookup(element);
    });
}

function addAutocompleteCityLookup(element) {
    const autoCompleteJS = new autoComplete({
        selector: () => element,
        threshold: 3,
        cache: true,
        data: {
            src: async (query) => {
                try {
                    let parentBody = element.closest(".accordion-body");
                    let countryElement = parentBody.querySelector('select[name*="country-"]');
                    let country = countryElement.options[countryElement.selectedIndex].value;
                    let url = cityLookupPath.replace('placeholder1', country).replace('placeholder2', query);
                    // Fetch Data from external Source
                    const source = await fetch(url);
                    // Data is array of `Objects` | `Strings`
                    const data = await source.json();

                    return data;
                } catch (error) {
                    return error;
                }
            },
            // Data 'Object' key to be searched
            keys: ["search"]
        },
        resultsList: {
            element: (list, data) => {
                if (!data.results.length) {
                    // Create "No Results" message element
                    const message = document.createElement("div");
                    // Add class to the created element
                    message.setAttribute("class", "no_result");
                    // Add message text content
                    message.innerHTML = `<span>Found No Results for "${data.query}"</span>`;
                    // Append message element to the results list
                    //list.prepend(message);
                }
            },
            noResults: true,
            maxResults: 50,
        },
        resultItem: {
            highlight: true
        },
        searchEngine: function (query, record) {
            // disable search engine because search is done on backend
            return record;
        },
        events: {
            input: {
                selection: (event) => {
                    const selection = event.detail.selection.value;
                    let parentBody = element.closest(".accordion-body");
                    let target = parentBody.querySelector('input[name*="city-"]');
                    target.value = selection.placeName;
                    element.value = selection.postalCode;
                },
            }
        }
    });
}

function addAutocompleteAddressLookup(element) {
    const autoCompleteJS = new autoComplete({
        selector: () => element,
        threshold: 2,
        cache: true,
        data: {
            src: async (query) => {
                try {
                    let url = addressLookupPath.replace('placeholder', query);
                    // Fetch Data from external Source
                    const source = await fetch(url);
                    // Data is array of `Objects` | `Strings`
                    const data = await source.json();
                    return data;
                } catch (error) {
                    return error;
                }
            },
        },
        resultsList: {
            maxResults: 5,
        },
        resultItem: {
            highlight: true,
            element: (item, data) => {
                let company = data.value.company || "";
                item.innerHTML = "";
                const s1 = document.createElement("span");
                const s2 = document.createElement("span");
                if(company.length) {
                    s1.innerText = company;
                    item.appendChild(s1);
                    item.appendChild(document.createElement("br"));
                }

                s2.innerText = data.value.address + ", " + data.value.zip + " " + data.value.city;;
                item.appendChild(s2);
            },
        },
        searchEngine: function (query, record) {
            // disable search engine because search is done on backend
            return record;
        },
        events: {
            input: {
                selection: (event) => {
                    const selection = event.detail.selection.value;
                    fillAddressFields(selection);
                },
            }
        }
    });
}

