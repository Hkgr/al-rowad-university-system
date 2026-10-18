import MonthlyPayroll from './MonthlyPayroll'

// Both existing entry routes now use one monthly accounting implementation and the original server guards.
export default function OwnerPayroll({ authorize }) {
  return <MonthlyPayroll authorize={authorize} />
}
