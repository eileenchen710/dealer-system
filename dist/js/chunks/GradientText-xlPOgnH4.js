import{r as o,j as t}from"./index-CYoNUv1H.js";function g({children:i,colors:n=["#111827","#6b7280","#9ca3af","#374151","#6b7280","#111827"],animationSpeed:e=4,className:r=""}){const a=o.useMemo(()=>({background:`linear-gradient(135deg, ${n.join(", ")})`,backgroundSize:"200% 200%",WebkitBackgroundClip:"text",WebkitTextFillColor:"transparent",backgroundClip:"text",animation:`gradientShift ${e}s ease-in-out infinite`,display:"inline-block"}),[n,e]);return t.jsxs(t.Fragment,{children:[t.jsx("style",{children:`
        @keyframes gradientShift {
          0%, 100% {
            background-position: 0% 50%;
          }
          50% {
            background-position: 100% 50%;
          }
        }
      `}),t.jsx("span",{className:r,style:a,children:i})]})}export{g as G};
