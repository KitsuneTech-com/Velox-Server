//Base class to establish prototype chain for Velox custom elements
export class VeloxElement extends HTMLElement {
    constructor(){
	super();
    }
}

//<vx-filterset> - Contains a set of <vx-filter> elements to be applied to the parent VeloxContainer element
export class VeloxFilterSetElement extends VeloxElement {
    constructor(){
	super();
    }
}
//<vx-filter> - An individual data filtering rule. Must be used within a <vx-filterset>.
export class VeloxFilterElement extends VeloxElement {
    constructor(){
	super();
    }
}

//Prototype class for Velox data containers
//(This is the central binding element for Velox data sets)
export class VeloxContainerElement extends VeloxElement {
    constructor(){
        super();
        this.veloxDataObject = null;
    }
    connectedCallback(){
        if (this.getAttribute("query")){
            try {
                this.veloxDataObject = new VeloxData(this.getAttribute("query"),this.generate);
            }
            catch(ex){
                throw new Error("Unable to populate data. Details: "+ ex.message);
            }
        }
    }
    generate(){
        
    }
}

//<vx-card> - VeloxContainer element that shows a single record at a time
export class VeloxCardElement extends VeloxContainerElement {
    constructor(){
	super();
    }
}

//<vx-table> - VeloxContainer element that shows a formatted dataset
export class VeloxTableElement extends VeloxContainerElement {
    constructor(){
	super();
    }
}

//<vx-column> - Column header for <vx-table> dataset
export class VeloxColumnElement extends VeloxElement {
    constructor(){
	super();
    }
}
//<vx-cell> - Cell element for individual data
//(user-defined as template and replicated as necessary by VeloxJS code)
export class VeloxCellElement extends VeloxElement {
    constructor(){
	super();
	this.attachShadow({mode: open});
        this.tabOrder = 0;        
    }
}

//VeloxControl
//----------------
//Prototype class for user-editable Velox elements
export class VeloxControl extends VeloxElement {
    static formAssociated = true;
    constructor(){
	super();
        this._internals = this.attachInternals();
        this._value = this.getAttribute("value");
	this.attachShadow({mode: open});
    }
    get name(){
        return this.getAttribute("name");
    }
    set name(name){
        this.setAttribute("name",name);
    }
    get value(){
        return this._value;
    }
    set value(value){
        this.setAttribute("value",value);
        this._internals.setFormValue(value);
    }
    get validity(){
        return this.internals_.validity;
    }
    get validationMessage(){
        return this.internals_.validationMessage;
    }
    get willValidate(){
        return this.internals_.willValidate;
    }

    checkValidity(){
        return this.internals_.checkValidity();
    }
    reportValidity(){
        return this.internals_.reportValidity();
    }
}
//<vx-text> - General equivalent to text-based <input>, but toggles to <span> on blur
export class VeloxTextElement extends VeloxControl {
    constructor(){
	super();
        this._control = document.createElement("input");
        this._control.setAttribute("type",this.getAttribute("type"));
        this._span = document.createElement("span");
        this._span.appendChild(document.createElement("slot"));
        
        //TODO - attach appropriate element to shadow root (with focus/blur listeners
        //to substitute the control if needed); make sure that the control and span
        //are of equal size to avoid layout shift
        
    }
    get type(){
        return this.getAttribute("type");
    }
    set type(type){
        this.setAttribute("type",type);    
    }
    get value(){
        
    }
    set value(value){
        this._span.innerHTML = value;
        this._control.value = value;
    }
}
//<vx-select> - Velox wrapper for <select>
export class VeloxSelectElement extends VeloxControl {
    constructor(){
	super();
        const elem = document.createElement("select");
    }
}
//<vx-fieldset> - Container element for multiple-choice elements
// (<vx-checkbox> or <vx-radio>); associated data represents the
// sum of the elements within
export class VeloxFieldsetElement extends VeloxControl {
    constructor(){
	super();
    }
}
//<vx-checkbox> - Velox wrapper for <input type="select">
export class VeloxCheckboxElement extends VeloxControl {
    constructor(){
	super();
        const elem = document.createElement("input");
        elem.type = "checkbox";
    }
}
//<vx-radio> - Velox wrapper for <input type="radio">
export class VeloxRadioElement extends VeloxControl {
    constructor(){
	super();
        const elem = document.createElement("input");
        elem.type = "radio";
    }
}

//CustomElementRegistry element definitions
window.customElements.define('vx-filterset',VeloxFilterSetElement);
window.customElements.define('vx-filter',VeloxFilterElement);
window.customElements.define('vx-card',VeloxCardElement);
window.customElements.define('vx-table',VeloxTableElement);
window.customElements.define('vx-column',VeloxColumnElement);
window.customElements.define('vx-cell',VeloxCellElement);
window.customElements.define('vx-text',VeloxTextElement);
window.customElements.define('vx-select',VeloxSelectElement);
window.customElements.define('vx-fieldset',VeloxFieldsetElement);
window.customElements.define('vx-checkbox',VeloxCheckboxElement);
window.customElements.define('vx-radio',VeloxRadioElement);
